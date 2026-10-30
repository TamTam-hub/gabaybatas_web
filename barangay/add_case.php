<?php
session_start();

require_once '../config/database.php';
require_once '../config/case_types.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/case_intake_form.php';

gabaybatas_require_role(['barangay'], '../login.php');
gabaybatas_block_barangay_delete_requests();

$currentBarangay = gabaybatas_get_user_barangay($conn);

// Walk-in complaints are entered with the same fields as the resident app's
// Submit a Case page, so KP Forms 7–9 are filled in the same way.
const ACS_MAX_IMAGES = 3;
const ACS_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];
const ACS_VIDEO_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp'];

$form = gabaybatas_intake_from_post();

$appTimezone = new DateTimeZone('Asia/Manila');
$filedAtDisplay = (new DateTimeImmutable('now', $appTimezone))->format('M d, Y');

/** Uploaded files of one input as a flat list of successful uploads. */
$uploadedFiles = static function (string $input): array {
    $f = $_FILES[$input] ?? null;
    if (!$f) {
        return [];
    }
    $files = [];
    $names = (array)$f['name'];
    foreach ($names as $i => $name) {
        $error = is_array($f['error']) ? $f['error'][$i] : $f['error'];
        $size = is_array($f['size']) ? $f['size'][$i] : $f['size'];
        if ($error === UPLOAD_ERR_OK && $size > 0) {
            $files[] = [
                'name'     => basename((string)$name),
                'tmp_name' => is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'],
                'type'     => (string)(is_array($f['type']) ? $f['type'][$i] : $f['type']),
                'size'     => (int)$size,
            ];
        }
    }
    return $files;
};

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $images = $uploadedFiles('evidence_images');
    $videos = $uploadedFiles('evidence_video');

    $errors = gabaybatas_intake_validate($form);
    if (count($images) > ACS_MAX_IMAGES) {
        $errors['evidence'] = 'Attach up to ' . ACS_MAX_IMAGES . ' photos.';
    }
    foreach ($images as $img) {
        if (!in_array(strtolower(pathinfo($img['name'], PATHINFO_EXTENSION)), ACS_IMAGE_EXT, true)) {
            $errors['evidence'] = 'Photos must be JPG, PNG, WEBP, GIF or BMP.';
        }
    }
    if (count($videos) > 1) {
        $errors['evidence'] = 'Attach only one video.';
    } elseif ($videos && !in_array(strtolower(pathinfo($videos[0]['name'], PATHINFO_EXTENSION)), ACS_VIDEO_EXT, true)) {
        $errors['evidence'] = 'The video must be MP4, MOV, AVI, MKV, WEBM or 3GP.';
    }

    $statement = '';
    if (!$errors) {
        $statement = gabaybatas_complaint_statement(
            $form['complainant_name'],
            $form['complainant_age'],
            $form['complainant_area'],
            $form['respondent_name'],
            $form['respondent_area'],
            $form['reason']
        );

        $dup = $conn->prepare('SELECT id FROM cases WHERE name = ? AND complained_person = ? AND description = ? AND archived_at IS NULL LIMIT 1');
        $dup->bind_param('sss', $form['complainant_name'], $form['respondent_name'], $statement);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $errors['form'] = 'This complaint was already recorded. Check the case list before adding it again.';
        }
        $dup->close();
    }

    if (!$errors) {
        $now = (new DateTimeImmutable('now', $appTimezone))->format('Y-m-d H:i:s');
        $contact = preg_replace('/[\s-]/', '', $form['contact']);
        $complainantPurok = mb_substr($form['complainant_area'], 0, 50);
        $respondentPurok = mb_substr($form['respondent_area'], 0, 50);

        $conn->begin_transaction();
        try {
            // Filed at the barangay hall: it goes straight to review by the Lupon Secretary.
            $stmt = $conn->prepare(
                "INSERT INTO cases (name, complainant_address, complainant_age, complained_person, respondent_address,
                                    barangay, contact, case_type, description, status, kp_status,
                                    complainant_purok, respondent_purok, date_submitted)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'under_review', ?, ?, ?)"
            );
            $stmt->bind_param(
                'ssssssssssss',
                $form['complainant_name'], $form['complainant_address'], $form['complainant_age'],
                $form['respondent_name'], $form['respondent_address'], $currentBarangay, $contact,
                $form['case_type'], $statement, $complainantPurok, $respondentPurok, $now
            );
            $stmt->execute();
            $newId = (int)$stmt->insert_id;
            $stmt->close();

            $caseCode = 'C-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);
            $upd = $conn->prepare('UPDATE cases SET case_id = ? WHERE id = ? LIMIT 1');
            $upd->bind_param('si', $caseCode, $newId);
            $upd->execute();
            $upd->close();

            // Evidence is stored like the app's uploads, so it shows on the case pages.
            $storageDir = __DIR__ . '/../storage/case_evidence';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0777, true);
            }
            $walkInUser = 0;
            $evStmt = $conn->prepare(
                'INSERT INTO api_case_evidence (case_id, user_id, evidence_type, stored_filename, original_filename, mime_type, file_size, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            foreach ([['image', $images], ['video', $videos]] as [$kind, $files]) {
                foreach ($files as $file) {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], $storageDir . '/' . $storedName)) {
                        continue;
                    }
                    $mime = $file['type'] !== '' && $file['type'] !== 'application/octet-stream'
                        ? $file['type']
                        : ($kind === 'image' ? 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext) : 'video/' . ($ext === 'mov' ? 'quicktime' : ($ext === '3gp' ? '3gpp' : $ext)));
                    $evStmt->bind_param('iissssi', $newId, $walkInUser, $kind, $storedName, $file['name'], $mime, $file['size']);
                    $evStmt->execute();
                }
            }
            $evStmt->close();

            $title = 'Complaint filed at the barangay hall';
            $detail = 'Entered by ' . ($_SESSION['name'] ?? 'barangay staff') . '; under review by the Lupon Secretary.';
            $staffId = (int)($_SESSION['user_id'] ?? 0);
            $tl = $conn->prepare(
                "INSERT INTO kp_timeline_events (case_id, stage, status, event_type, title, details, event_at, created_by, created_at)
                 VALUES (?, 'intake', 'under_review', 'case_submitted', ?, ?, NOW(), ?, NOW())"
            );
            $tl->bind_param('issi', $newId, $title, $detail, $staffId);
            $tl->execute();
            $tl->close();

            $conn->commit();
            header('Location: cases.php?created=' . rawurlencode($caseCode));
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Add case failed: ' . $e->getMessage());
            $errors['form'] = 'Unable to save the case. Please try again.';
        }
    }
}

$err = static fn(string $key): string => isset($errors[$key])
    ? '<small class="acs-error">' . htmlspecialchars($errors[$key]) . '</small>'
    : '';
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-title">Add Case</div>
        <div class="topbar-right">
            <div class="icon-btn" title="Notifications"><i class="uil uil-bell"></i></div>
            <div class="topbar-divider"></div>
            <div class="user-pill">
                <div class="user-avatar"><i class="uil uil-user"></i></div>
                <div class="user-text">
                    <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Barangay Official') ?></strong>
                    <span>Barangay Administrator</span>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body acs-body">
        <div class="acs-head">
            <p class="acs-sub">Record a complaint filed in person at the barangay hall. The fields are the same as the resident app's Submit a Case, so the KP forms are filled in automatically.</p>
            <a href="cases.php" class="acs-back-btn"><i class="uil uil-arrow-left"></i> Back to Cases</a>
        </div>

        <?php if ($errors): ?>
            <div class="acs-alert error"><i class="uil uil-exclamation-circle"></i>
                <?= htmlspecialchars($errors['form'] ?? 'Please correct the highlighted fields.') ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="acs-form" id="addCaseForm" novalidate>
            <?php gabaybatas_intake_render($form, $errors, [
                'date_filed'  => $filedAtDisplay,
                'status_html' => '<div class="acs-status"><span class="acs-status-dot"></span> Under Review</div>'
                    . '<small class="acs-hint">Set automatically. Review the complaint next in Case Process.</small>',
            ]); ?>

            <!-- Evidence -->
            <div class="card-box acs-card">
                <div class="acs-card-title"><i class="uil uil-paperclip"></i> Evidence (Optional)</div>
                <!-- The named inputs hold the files that are sent; the pickers only add to them. -->
                <input type="file" name="evidence_images[]" id="acsImages" accept="image/*" multiple hidden>
                <input type="file" name="evidence_video" id="acsVideo" accept="video/*" hidden>
                <input type="file" id="acsImagePicker" accept="image/*" multiple hidden>

                <div class="acs-field">
                    <label>Photos <span class="acs-count" id="acsImageCount">0 of <?= ACS_MAX_IMAGES ?></span></label>
                    <div class="acs-thumbs" id="acsThumbs">
                        <button type="button" class="acs-add-tile" id="acsAddImage">
                            <i class="uil uil-image-plus"></i><span>Add photo</span>
                        </button>
                    </div>
                </div>

                <div class="acs-field" style="margin-top:14px;">
                    <label>Video <span class="acs-count">optional, 1 only</span></label>
                    <div id="acsVideoBox">
                        <button type="button" class="acs-add-video" id="acsAddVideo"><i class="uil uil-video"></i> Add video</button>
                    </div>
                </div>
                <?= $err('evidence') ?>
                <?php if ($errors): ?>
                    <small class="acs-hint">For security, browsers clear attached files after a correction. Please attach the evidence again.</small>
                <?php endif; ?>
            </div>

            <div class="acs-actions">
                <a href="cases.php" class="acs-cancel">Cancel</a>
                <button type="submit" class="acs-save"><i class="uil uil-save"></i> Save Case</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('addCaseForm');
    if (!form) return;

    // ── Evidence: photos are added one or several at a time and shown as thumbnails ──
    const MAX_IMAGES = <?= ACS_MAX_IMAGES ?>;
    const imageInput = document.getElementById('acsImages');
    const imagePicker = document.getElementById('acsImagePicker');
    const thumbs = document.getElementById('acsThumbs');
    const addImage = document.getElementById('acsAddImage');
    const videoInput = document.getElementById('acsVideo');
    const videoBox = document.getElementById('acsVideoBox');
    let photos = [];

    const notify = (title, message) => window.AdminToast
        ? window.AdminToast.show('error', title, message)
        : alert(message);
    const sizeLabel = bytes => bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';

    function syncPhotos() {
        const transfer = new DataTransfer();
        photos.forEach(file => transfer.items.add(file));
        imageInput.files = transfer.files;

        thumbs.querySelectorAll('.acs-thumb').forEach(el => {
            URL.revokeObjectURL(el.dataset.url);
            el.remove();
        });
        photos.forEach((file, index) => {
            const url = URL.createObjectURL(file);
            const tile = document.createElement('div');
            tile.className = 'acs-thumb';
            tile.dataset.url = url;
            tile.title = file.name;
            tile.innerHTML = '<img alt=""><button type="button" class="acs-thumb-remove" aria-label="Remove photo">&times;</button><span class="acs-thumb-name"></span>';
            tile.querySelector('img').src = url;
            tile.querySelector('.acs-thumb-name').textContent = file.name;
            tile.querySelector('button').addEventListener('click', () => {
                photos.splice(index, 1);
                syncPhotos();
            });
            thumbs.insertBefore(tile, addImage);
        });
        addImage.hidden = photos.length >= MAX_IMAGES;
        document.getElementById('acsImageCount').textContent = photos.length + ' of ' + MAX_IMAGES;
    }

    addImage.addEventListener('click', () => imagePicker.click());
    imagePicker.addEventListener('change', () => {
        const chosen = Array.from(imagePicker.files).filter(file => file.type.startsWith('image/'));
        const room = MAX_IMAGES - photos.length;
        if (chosen.length > room) {
            notify('Too many photos', 'Only ' + MAX_IMAGES + ' photos can be attached. The first ' + room + ' were added.');
        }
        photos = photos.concat(chosen.slice(0, room));
        imagePicker.value = '';
        syncPhotos();
    });

    function renderVideo() {
        const file = videoInput.files[0];
        videoBox.innerHTML = '';
        if (!file) {
            const add = document.createElement('button');
            add.type = 'button';
            add.className = 'acs-add-video';
            add.innerHTML = '<i class="uil uil-video"></i> Add video';
            add.addEventListener('click', () => videoInput.click());
            videoBox.appendChild(add);
            return;
        }
        const card = document.createElement('div');
        card.className = 'acs-video-card';
        card.innerHTML = '<i class="uil uil-film"></i><div><strong></strong><span></span></div><button type="button" class="acs-video-remove">Remove</button>';
        card.querySelector('strong').textContent = file.name;
        card.querySelector('span').textContent = sizeLabel(file.size);
        card.querySelector('button').addEventListener('click', () => {
            videoInput.value = '';
            renderVideo();
        });
        videoBox.appendChild(card);
    }
    videoInput.addEventListener('change', renderVideo);
    renderVideo();
    syncPhotos();

    let confirmed = false;
    form.addEventListener('submit', async function (event) {
        if (confirmed) return;
        event.preventDefault();
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        const ok = window.AdminConfirm
            ? await window.AdminConfirm.confirm({
                title: 'Save New Case?',
                message: 'The case will be saved as Under Review. Please check the complainant, respondent and statement before saving.',
                confirmText: 'Save Case',
                cancelText: 'Review Again'
            })
            : window.confirm('Save this case?');
        if (!ok) return;
        confirmed = true;
        form.submit();
    });
})();
</script>

<?php gabaybatas_intake_assets('addCaseForm'); ?>

<style>
.acs-field label .acs-count{text-transform:none;letter-spacing:0;font-weight:600;color:#94a3b8;margin-left:6px;}
.acs-thumbs{display:flex;flex-wrap:wrap;gap:10px;}
.acs-thumb,.acs-add-tile{width:120px;height:120px;border-radius:12px;position:relative;overflow:hidden;flex-shrink:0;}
.acs-thumb{border:1px solid #e2e8f0;background:#f8fafc;}
.acs-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
.acs-thumb-name{position:absolute;left:0;right:0;bottom:0;padding:4px 6px;font-size:10.5px;color:#fff;background:linear-gradient(transparent,rgba(15,23,42,.75));white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.acs-thumb-remove{position:absolute;top:6px;right:6px;width:24px;height:24px;border-radius:50%;border:none;background:rgba(15,23,42,.7);color:#fff;font-size:16px;line-height:1;cursor:pointer;}
.acs-thumb-remove:hover{background:#dc2626;}
.acs-add-tile{border:1.5px dashed #93c5fd;background:#eff6ff;color:#1d4ed8;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;cursor:pointer;font-size:12px;font-weight:600;}
.acs-add-tile i{font-size:26px;}
.acs-add-tile:hover{background:#dbeafe;}
.acs-add-tile[hidden]{display:none;}
.acs-add-video{display:inline-flex;align-items:center;gap:6px;border:1.5px dashed #93c5fd;background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:10px 14px;font-size:12.5px;font-weight:600;cursor:pointer;}
.acs-add-video:hover{background:#dbeafe;}
.acs-video-card{display:flex;align-items:center;gap:10px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:10px;padding:10px 12px;max-width:460px;}
.acs-video-card i{font-size:24px;color:#1d4ed8;}
.acs-video-card div{flex:1;min-width:0;display:flex;flex-direction:column;}
.acs-video-card strong{font-size:13px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.acs-video-card span{font-size:11.5px;color:#64748b;}
.acs-video-remove{border:1px solid #fecaca;background:#fff;color:#dc2626;border-radius:8px;padding:6px 10px;font-size:12px;font-weight:600;cursor:pointer;}
</style>
