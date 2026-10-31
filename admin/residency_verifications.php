<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';

$conn->query("CREATE TABLE IF NOT EXISTS residency_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(191) NOT NULL,
    date_of_birth DATE NULL,
    complete_address TEXT NOT NULL,
    barangay VARCHAR(191) NOT NULL,
    government_id_type VARCHAR(80) NOT NULL,
    government_id_last4 VARCHAR(4) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    reviewer_note TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_residency_user_status (user_id, status),
    KEY idx_residency_barangay_status (barangay, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS residency_verification_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    verification_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(40) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_verification_document_type (verification_id, document_type),
    KEY idx_document_verification (verification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$message = '';
$messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verificationId = (int)($_POST['verification_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? 'review'));
    $status = trim((string)($_POST['status'] ?? ''));
    $reviewerNote = trim((string)($_POST['reviewer_note'] ?? ''));
    if ($action === 'delete_rejected') {
        $storedFiles = [];
        $conn->begin_transaction();
        try {
            $lookup = $conn->prepare(
                'SELECT status FROM residency_verifications WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            if (!$lookup) {
                throw new RuntimeException('Unable to prepare deletion lookup.');
            }
            $lookup->bind_param('i', $verificationId);
            if (!$lookup->execute()) {
                throw new RuntimeException('Unable to verify deletion target.');
            }
            $target = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!$target || (string)$target['status'] !== 'rejected') {
                throw new RuntimeException('Only rejected verifications can be deleted.');
            }

            $documents = $conn->prepare(
                'SELECT stored_filename FROM residency_verification_documents WHERE verification_id = ?'
            );
            if (!$documents) {
                throw new RuntimeException('Unable to prepare document lookup.');
            }
            $documents->bind_param('i', $verificationId);
            $documents->execute();
            $documentResult = $documents->get_result();
            while ($document = $documentResult->fetch_assoc()) {
                $storedFiles[] = __DIR__ . '/../storage/residency_documents/' . basename((string)$document['stored_filename']);
            }
            $documents->close();

            $deleteDocuments = $conn->prepare('DELETE FROM residency_verification_documents WHERE verification_id = ?');
            $deleteVerification = $conn->prepare('DELETE FROM residency_verifications WHERE id = ? AND status = \'rejected\' LIMIT 1');
            if (!$deleteDocuments || !$deleteVerification) {
                throw new RuntimeException('Unable to prepare rejected verification deletion.');
            }
            $deleteDocuments->bind_param('i', $verificationId);
            $deleteVerification->bind_param('i', $verificationId);
            if (!$deleteDocuments->execute() || !$deleteVerification->execute() || $deleteVerification->affected_rows !== 1) {
                throw new RuntimeException('Unable to delete rejected verification.');
            }
            $deleteDocuments->close();
            $deleteVerification->close();
            $conn->commit();
            foreach ($storedFiles as $storedFile) {
                if (is_file($storedFile)) {
                    unlink($storedFile);
                }
            }
            $message = 'Rejected verification and its documents were deleted.';
        } catch (Throwable $error) {
            $conn->rollback();
            $message = $error->getMessage();
            $messageType = 'error';
        }
    } elseif ($verificationId <= 0 || !in_array($status, ['approved', 'rejected', 'resubmit'], true)) {
        $message = 'Invalid review request.';
        $messageType = 'error';
    } elseif ($status !== 'approved' && $reviewerNote === '') {
        $message = 'A note is required when rejecting or requesting resubmission.';
        $messageType = 'error';
    } else {
        $reviewerId = (int)($_SESSION['user_id'] ?? 0);
        $reviewedAt = date('Y-m-d H:i:s');
        $stmt = $conn->prepare('UPDATE residency_verifications SET status = ?, reviewer_note = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? LIMIT 1');
        $stmt->bind_param('ssissi', $status, $reviewerNote, $reviewerId, $reviewedAt, $reviewedAt, $verificationId);
        if ($stmt->execute()) {
            $message = 'Verification review saved.';
            // Tell the resident about the decision (read by the app through api/v1/notifications.php).
            $owner = $conn->prepare('SELECT user_id FROM residency_verifications WHERE id = ? LIMIT 1');
            $owner->bind_param('i', $verificationId);
            $owner->execute();
            $residentId = (int)($owner->get_result()->fetch_assoc()['user_id'] ?? 0);
            $owner->close();
            $noteLine = $reviewerNote !== '' ? ' Note from the barangay: ' . $reviewerNote : '';
            $notice = [
                'approved' => ['Residency verified', 'Your residency in Barangay Muñoz has been verified. You can now submit a case.' . $noteLine],
                'resubmit' => ['Resubmission needed', 'Please resubmit your residency verification in the app.' . $noteLine],
                'rejected' => ['Residency verification rejected', 'Your residency verification was not approved.' . $noteLine . ' You may submit a new request.'],
            ][$status];
            if ($residentId > 0) {
                $notify = $conn->prepare("INSERT INTO api_notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'residency_verification', ?)");
                if ($notify) {
                    $notify->bind_param('isss', $residentId, $notice[0], $notice[1], $reviewedAt);
                    $notify->execute();
                    $notify->close();
                }
            }
        } else {
            $message = 'Unable to save the review.';
            $messageType = 'error';
        }
        $stmt->close();
    }

    // Reload with GET so refreshing the page does not send the review again.
    $_SESSION['rv_flash'] = ['message' => $message, 'type' => $messageType];
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'residency_verifications.php'));
    exit();
}
if (isset($_SESSION['rv_flash'])) {
    $message = (string)$_SESSION['rv_flash']['message'];
    $messageType = (string)$_SESSION['rv_flash']['type'];
    unset($_SESSION['rv_flash']);
}

$statusFilter = trim((string)($_GET['status'] ?? 'pending'));
$allowedFilters = ['pending', 'approved', 'rejected', 'resubmit', 'all'];
if (!in_array($statusFilter, $allowedFilters, true)) $statusFilter = 'pending';
$searchTerm = trim((string)($_GET['search'] ?? ''));
$displayStatusFilter = $searchTerm !== '' ? 'all' : $statusFilter;
$whereParts = [];
if ($statusFilter !== 'all' && $searchTerm === '') {
    $whereParts[] = "v.status = '" . $conn->real_escape_string($statusFilter) . "'";
}
if ($searchTerm !== '') {
    $safeSearch = $conn->real_escape_string($searchTerm);
    $whereParts[] = "(v.full_name LIKE '%{$safeSearch}%'
        OR v.complete_address LIKE '%{$safeSearch}%'
        OR v.government_id_type LIKE '%{$safeSearch}%'
        OR u.email LIKE '%{$safeSearch}%')";
}
$where = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';
$result = $conn->query("SELECT v.*, u.email, r.name AS reviewer_name,
    (SELECT GROUP_CONCAT(CONCAT(d.id, '|', d.document_type, '|', d.original_filename, '|', d.mime_type) ORDER BY d.id SEPARATOR ';;')
     FROM residency_verification_documents d WHERE d.verification_id = v.id) AS document_items
    FROM residency_verifications v
    LEFT JOIN users u ON u.id = v.user_id
    LEFT JOIN users r ON r.id = v.reviewed_by
    {$where}
    ORDER BY v.id DESC");
$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['documents'] = [];
        foreach (explode(';;', (string)($row['document_items'] ?? '')) as $item) {
            $parts = explode('|', $item, 4);
            if (count($parts) === 4 && (int)$parts[0] > 0) {
                $row['documents'][] = [
                    'id' => (int)$parts[0],
                    'type' => $parts[1],
                    'name' => $parts[2],
                    'mime' => $parts[3],
                ];
            }
        }
        $rows[] = $row;
    }
}

$statusCounts = array_fill_keys($allowedFilters, 0);
$countResult = $conn->query("SELECT status, COUNT(*) AS total FROM residency_verifications GROUP BY status");
if ($countResult) {
    while ($countRow = $countResult->fetch_assoc()) {
        $countStatus = strtolower(trim((string)($countRow['status'] ?? '')));
        if (isset($statusCounts[$countStatus])) {
            $statusCounts[$countStatus] = (int)$countRow['total'];
        }
    }
}
$statusCounts['all'] = array_sum($statusCounts);
$pageBarangay = 'Barangay Muñoz';

require_once '../includes/header_admin.php';
require_once '../includes/sidebar_admin.php';
?>
<main class="main rv-page">
    <div class="topbar">
        <div class="topbar-left"><div class="topbar-title">Residency Verification</div><span class="topbar-badge">Barangay Muñoz</span></div>
        <div class="topbar-right"><div class="notif-btn"><i class="uil uil-bell"></i></div><div class="topbar-sep"></div><div class="user-pill"><div class="user-avatar"><i class="uil uil-user"></i></div><div class="user-text"><strong><?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div></div>
    </div>

    <div class="page-body rv-body">
        <div class="rv-hero">
            <div><div class="rv-kicker"><i class="uil uil-shield-check"></i> Resident identity desk</div><p>Review identity and address submissions for <?= htmlspecialchars($pageBarangay) ?>.</p></div>
        </div>

        <?php if ($message !== ''): ?><div class="rv-alert <?= $messageType === 'error' ? 'is-error' : 'is-success' ?>"><?= $messageType === 'error' ? '<i class="uil uil-exclamation-circle"></i>' : gabaybatas_lottie('success', 24, 'uil-check-circle', true) ?><span><?= htmlspecialchars($message) ?></span></div><?php endif; ?>

        <div class="rv-stats">
            <?php foreach ([['pending','Pending','uil-clock','amber'],['approved','Approved','uil-check-circle','green'],['resubmit','Resubmission','uil-refresh','blue'],['all','Total submissions','uil-files-landscapes','slate']] as $stat): ?>
                <a class="rv-stat" href="?status=<?= $stat[0] ?>"><span class="rv-stat-icon <?= $stat[3] ?>"><i class="uil <?= $stat[2] ?>"></i></span><span><strong><?= $statusCounts[$stat[0]] ?></strong><small><?= $stat[1] ?></small></span></a>
            <?php endforeach; ?>
        </div>

        <section class="rv-panel">
            <div class="rv-panel-head"><div><h2>Verification queue</h2><p><?= $searchTerm !== '' ? 'Search results across all statuses' : ($statusFilter === 'all' ? 'All submitted verification records' : 'Showing ' . htmlspecialchars($statusFilter) . ' submissions') ?></p></div><span class="rv-count"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span></div>
            <div class="rv-controls">
                <form method="get" class="rv-search-form">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($displayStatusFilter) ?>">
                    <i class="uil uil-search"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Search by resident, email, address, or ID type" aria-label="Search verification submissions">
                    <?php if ($searchTerm !== ''): ?><a href="?status=<?= urlencode($statusFilter) ?>" class="rv-clear-search" title="Clear search" aria-label="Clear search"><i class="uil uil-times"></i></a><?php endif; ?>
                    <button type="submit">Search</button>
                </form>
                <nav class="rv-tabs" aria-label="Verification status filters">
                    <?php foreach ($allowedFilters as $filter): ?><a href="?status=<?= urlencode($filter) ?>" class="<?= $displayStatusFilter === $filter ? 'active' : '' ?>"><span><?= htmlspecialchars($filter === 'all' ? 'All' : ucfirst($filter)) ?></span><b><?= $statusCounts[$filter] ?></b></a><?php endforeach; ?>
                </nav>
            </div>
            <div class="rv-table-wrap"><table class="rv-table"><thead><tr><th>Resident</th><th>Address</th><th>Identity</th><th>Documents</th><th>Status</th><th>Submitted</th><th class="rv-review-col">Review</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="7"><div class="rv-empty"><?= gabaybatas_lottie($searchTerm !== '' ? 'search-file' : 'empty-box', 130, 'uil-folder-check') ?><strong><?= $searchTerm !== '' ? 'No submissions match your search' : 'No submissions in this view' ?></strong><span>New resident verification requests will appear here.</span></div></td></tr><?php endif; ?>
            <?php foreach ($rows as $row): $rowStatus = strtolower(trim((string)$row['status'])); $statusClass = in_array($rowStatus, ['approved','rejected','resubmit'], true) ? $rowStatus : 'pending'; ?>
                <tr>
                    <td><div class="rv-person"><span class="rv-avatar"><?= htmlspecialchars(strtoupper(substr((string)$row['full_name'], 0, 1))) ?></span><span><strong><?= htmlspecialchars((string)$row['full_name']) ?></strong><small><?= htmlspecialchars((string)($row['email'] ?? '')) ?></small></span></div></td>
                    <td><strong><?= htmlspecialchars($pageBarangay) ?></strong><small class="rv-muted"><?= htmlspecialchars((string)$row['complete_address']) ?></small></td>
                    <td><strong><?= htmlspecialchars((string)$row['government_id_type']) ?></strong><?php if (!empty($row['government_id_last4'])): ?><small class="rv-muted">Ending <?= htmlspecialchars((string)$row['government_id_last4']) ?></small><?php endif; ?><small class="rv-muted">Born <?= !empty($row['date_of_birth']) && strtotime((string)$row['date_of_birth']) ? htmlspecialchars(date('M d, Y', strtotime((string)$row['date_of_birth']))) : '—' ?></small></td>
                    <td><div class="rv-docs"><?php foreach ($row['documents'] as $document): $documentLabel = $document['type'] === 'proof_of_residence' ? 'Barangay Indigency' : 'Government ID'; ?><a href="verification_document.php?id=<?= $document['id'] ?>" class="rv-document-link" data-document-url="verification_document.php?id=<?= $document['id'] ?>" data-document-mime="<?= htmlspecialchars($document['mime'], ENT_QUOTES) ?>" data-document-title="<?= htmlspecialchars($documentLabel, ENT_QUOTES) ?>" title="Open <?= htmlspecialchars($documentLabel) ?>"><i class="uil <?= $document['type'] === 'proof_of_residence' ? 'uil-home' : 'uil-card-atm' ?>"></i><?= htmlspecialchars($documentLabel) ?></a><?php endforeach; ?><?php if (!$row['documents']): ?><span class="rv-muted">No files</span><?php endif; ?></div></td>
                    <td><span class="rv-status <?= $statusClass ?>"><i class="uil <?= $statusClass === 'approved' ? 'uil-check' : ($statusClass === 'rejected' ? 'uil-times' : ($statusClass === 'resubmit' ? 'uil-refresh' : 'uil-clock')) ?>"></i><?= htmlspecialchars(ucfirst($rowStatus)) ?></span><?php if (!empty($row['reviewed_at'])): ?><small class="rv-muted">by <?= htmlspecialchars((string)($row['reviewer_name'] ?: 'Admin')) ?> · <?= htmlspecialchars(date('M d, Y', strtotime((string)$row['reviewed_at']))) ?></small><?php endif; ?><?php if (!empty($row['reviewer_note'])): ?><small class="rv-note">“<?= htmlspecialchars((string)$row['reviewer_note']) ?>”</small><?php endif; ?></td>
                    <td><strong><?= htmlspecialchars(date('M d, Y', strtotime((string)$row['created_at']))) ?></strong><small class="rv-muted"><?= htmlspecialchars(date('h:i A', strtotime((string)$row['created_at']))) ?></small></td>
                    <td><details class="rv-details"><summary><i class="uil uil-edit"></i> Review</summary><form method="post" class="rv-review-form"><button type="button" class="rv-review-close" aria-label="Close review form"><i class="uil uil-times"></i></button><input type="hidden" name="verification_id" value="<?= (int)$row['id'] ?>"><div class="rv-review-current">Current decision: <b><?= htmlspecialchars(ucfirst($rowStatus)) ?></b></div><label>Decision<select name="status" required><option value="" selected disabled>Choose a decision…</option><option value="approved">Approve</option><option value="resubmit">Request resubmission</option><option value="rejected">Reject</option></select></label><label>Note<textarea name="reviewer_note" placeholder="Required when rejecting or requesting resubmission. The resident will see this note."></textarea></label><button type="submit" class="rv-save-review"><i class="uil uil-check"></i> Save review</button></form></details><?php if ($rowStatus === 'rejected' && $statusFilter === 'rejected'): ?><form method="post" class="rv-delete-form"><input type="hidden" name="action" value="delete_rejected"><input type="hidden" name="verification_id" value="<?= (int)$row['id'] ?>"><button type="button" class="rv-delete-button" data-delete-id="<?= (int)$row['id'] ?>" data-delete-name="<?= htmlspecialchars((string)$row['full_name'], ENT_QUOTES) ?>"><i class="uil uil-trash-alt"></i> Delete</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
</main>

<div class="rv-document-modal" id="rvDocumentModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="rvDocumentTitle">
    <div class="rv-modal-backdrop" data-close-document></div>
    <div class="rv-modal-card"><div class="rv-modal-head"><div><span>Document preview</span><h2 id="rvDocumentTitle">Verification document</h2></div><div class="rv-modal-actions"><div class="rv-zoom-tools" id="rvZoomTools"><button type="button" data-zoom="out" aria-label="Zoom out">−</button><span id="rvZoomValue">100%</span><button type="button" data-zoom="in" aria-label="Zoom in">+</button><button type="button" data-zoom="reset" aria-label="Reset zoom">Reset</button></div><button type="button" class="rv-modal-close" data-close-document aria-label="Close document preview"><i class="uil uil-times"></i></button></div></div><div class="rv-modal-body"><img id="rvDocumentImage" alt="Verification document image"><iframe id="rvDocumentFrame" title="Verification document preview"></iframe></div></div>
</div>

<div class="rv-delete-modal" id="rvDeleteModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="rvDeleteTitle">
    <div class="rv-delete-backdrop" data-close-delete></div>
    <div class="rv-delete-card">
        <div class="rv-delete-icon"><i class="uil uil-trash-alt"></i></div>
        <button type="button" class="rv-delete-close" data-close-delete aria-label="Close delete confirmation"><i class="uil uil-times"></i></button>
        <h2 id="rvDeleteTitle">Delete rejected verification?</h2>
        <p>This will permanently remove <strong id="rvDeleteName">this resident's</strong> rejected verification and its uploaded documents. This action cannot be undone.</p>
        <form method="post" class="rv-delete-confirm-form">
            <input type="hidden" name="action" value="delete_rejected">
            <input type="hidden" name="verification_id" id="rvDeleteId">
            <div class="rv-delete-actions"><button type="button" class="rv-delete-cancel" data-close-delete>Cancel</button><button type="submit" class="rv-delete-confirm"><i class="uil uil-trash-alt"></i> Delete permanently</button></div>
        </form>
    </div>
</div>

<script>
const positionReviewForm = details => {
    const summary = details.querySelector('summary');
    const form = details.querySelector('.rv-review-form');
    if (!summary || !form) return;
    const buttonRect = summary.getBoundingClientRect();
    const formWidth = Math.min(280, window.innerWidth - 32);
    const left = Math.max(16, Math.min(buttonRect.right - formWidth, window.innerWidth - formWidth - 16));
    form.style.width = `${formWidth}px`;
    form.style.left = `${left}px`;
    form.style.top = `${Math.min(buttonRect.bottom + 8, window.innerHeight - form.offsetHeight - 16)}px`;
    if (buttonRect.bottom + 8 + form.offsetHeight > window.innerHeight - 16) {
        form.style.top = `${Math.max(16, buttonRect.top - form.offsetHeight - 8)}px`;
    }
};
document.querySelectorAll('.rv-details').forEach(details => {
    details.addEventListener('toggle', () => {
        if (!details.open) return;
        document.querySelectorAll('.rv-details[open]').forEach(other => {
            if (other !== details) other.removeAttribute('open');
        });
        requestAnimationFrame(() => positionReviewForm(details));
    });
});
window.addEventListener('resize', () => {
    const openReview = document.querySelector('.rv-details[open]');
    if (openReview) positionReviewForm(openReview);
});
document.querySelectorAll('.rv-review-close').forEach(button => {
    button.addEventListener('click', () => button.closest('details').removeAttribute('open'));
});

const rvDeleteModal = document.getElementById('rvDeleteModal');
const rvDeleteId = document.getElementById('rvDeleteId');
const rvDeleteName = document.getElementById('rvDeleteName');
const closeDeleteModal = () => {
    rvDeleteModal.classList.remove('is-open');
    rvDeleteModal.setAttribute('aria-hidden', 'true');
};
document.querySelectorAll('[data-delete-id]').forEach(button => {
    button.addEventListener('click', () => {
        rvDeleteId.value = button.dataset.deleteId;
        rvDeleteName.textContent = button.dataset.deleteName || 'this resident';
        rvDeleteModal.classList.add('is-open');
        rvDeleteModal.setAttribute('aria-hidden', 'false');
    });
});
document.querySelectorAll('[data-close-delete]').forEach(button => button.addEventListener('click', closeDeleteModal));

const rvDocumentModal = document.getElementById('rvDocumentModal');
const rvDocumentFrame = document.getElementById('rvDocumentFrame');
const rvDocumentImage = document.getElementById('rvDocumentImage');
const rvZoomTools = document.getElementById('rvZoomTools');
const rvZoomValue = document.getElementById('rvZoomValue');
let rvZoom = 1;
const updateZoom = () => {
    rvDocumentImage.style.transform = `scale(${rvZoom})`;
    rvZoomValue.textContent = `${Math.round(rvZoom * 100)}%`;
};
const rvDocumentTitle = document.getElementById('rvDocumentTitle');
const closeDocumentPreview = () => {
    rvDocumentModal.classList.remove('is-open');
    rvDocumentModal.setAttribute('aria-hidden', 'true');
    rvDocumentFrame.src = 'about:blank';
    rvDocumentImage.removeAttribute('src');
    rvDocumentImage.classList.remove('is-visible');
    rvDocumentFrame.classList.remove('is-visible');
    rvZoomTools.classList.remove('is-visible');
};
document.querySelectorAll('.rv-document-link').forEach(link => {
    link.addEventListener('click', event => {
        event.preventDefault();
        rvDocumentTitle.textContent = link.dataset.documentTitle || 'Verification document';
        rvZoom = 1;
        updateZoom();
        const isImage = (link.dataset.documentMime || '').startsWith('image/');
        if (isImage) {
            rvDocumentImage.src = link.dataset.documentUrl;
            rvDocumentImage.classList.add('is-visible');
            rvZoomTools.classList.add('is-visible');
        } else {
            rvDocumentFrame.src = link.dataset.documentUrl;
            rvDocumentFrame.classList.add('is-visible');
        }
        rvDocumentModal.classList.add('is-open');
        rvDocumentModal.setAttribute('aria-hidden', 'false');
    });
});
document.querySelectorAll('[data-zoom]').forEach(button => button.addEventListener('click', () => {
    if (button.dataset.zoom === 'in') rvZoom = Math.min(3, rvZoom + .25);
    if (button.dataset.zoom === 'out') rvZoom = Math.max(.5, rvZoom - .25);
    if (button.dataset.zoom === 'reset') rvZoom = 1;
    updateZoom();
}));
document.querySelectorAll('[data-close-document]').forEach(button => button.addEventListener('click', closeDocumentPreview));
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    closeDocumentPreview();
    closeDeleteModal();
    document.querySelectorAll('.rv-details[open]').forEach(details => details.removeAttribute('open'));
});
</script>

<style>
.rv-modal-card{position:relative;z-index:1;display:flex;flex-direction:column;width:min(920px,100%);height:min(760px,90vh);overflow:hidden;border:1px solid rgba(255,255,255,.3);border-radius:14px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.3)}.rv-modal-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 18px;border-bottom:1px solid #e2e8f0;background:#f8fafc}.rv-modal-head span{display:block;color:#0d9488;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase}.rv-modal-head h2{margin-top:2px;color:#102a43;font-family:'Source Serif 4',serif;font-size:20px}.rv-modal-close{display:grid;place-items:center;width:34px;height:34px;border:0;border-radius:8px;background:#e2e8f0;color:#475569;font-size:19px;cursor:pointer}.rv-modal-close:hover{background:#fecdd3;color:#be123c}.rv-modal-body{flex:1;background:#e2e8f0;padding:12px}.rv-modal-body iframe{display:block;width:100%;height:100%;border:0;border-radius:8px;background:#fff}@keyframes rvModalIn{from{opacity:0;transform:scale(.98)}to{opacity:1;transform:scale(1)}}.rv-empty{display:flex;align-items:center;flex-direction:column;gap:5px;padding:58px 20px;color:#64748b}.rv-empty i{font-size:34px;color:#5eead4}.rv-empty strong{color:#334155;font-size:14px}.rv-empty span{font-size:12px}@media(max-width:900px){.rv-stats{grid-template-columns:repeat(2,1fr)}.rv-hero{align-items:flex-start;flex-direction:column}.rv-hero-mark{white-space:normal}}@media(max-width:560px){.rv-body{padding:16px 12px 30px}.rv-stats{gap:8px}.rv-stat{padding:11px}.rv-panel-head{padding:16px}.rv-controls{padding:0 16px 14px}.rv-search-form{max-width:none}.rv-tabs{gap:5px}.rv-hero{padding:19px}.rv-hero h1{font-size:25px}.rv-document-modal{padding:10px}.rv-modal-card{height:92vh}}
</style>
<style>
.rv-modal-actions{display:flex;align-items:center;gap:10px}.rv-zoom-tools{display:none;align-items:center;gap:5px}.rv-zoom-tools.is-visible{display:flex}.rv-zoom-tools button{height:28px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;font:inherit;font-size:12px;font-weight:700;cursor:pointer}.rv-zoom-tools button[data-zoom="in"],.rv-zoom-tools button[data-zoom="out"]{width:28px;font-size:17px;line-height:1}.rv-zoom-tools button[data-zoom="reset"]{padding:0 8px;font-size:10px}.rv-zoom-tools button:hover{border-color:#14b8a6;color:#0f766e;background:#f0fdfa}.rv-zoom-tools span{min-width:42px;text-align:center;color:#64748b;font-size:10px;font-weight:700}.rv-modal-body{display:flex;align-items:center;justify-content:center;overflow:auto}.rv-modal-body img{display:none;max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;transform-origin:center center;transition:transform .16s ease;cursor:zoom-in}.rv-modal-body img.is-visible{display:block}.rv-modal-body iframe{display:none}.rv-modal-body iframe.is-visible{display:block}.rv-document-link{cursor:pointer}.rv-document-link:hover{text-decoration:none!important}
.rv-delete-form{margin-top:8px}.rv-delete-button{display:inline-flex;align-items:center;gap:5px;border:1px solid #fecdd3;border-radius:6px;padding:7px 10px;background:#fff1f2;color:#be123c;font:inherit;font-size:10px;font-weight:700;cursor:pointer}.rv-delete-button:hover{background:#ffe4e6;border-color:#fda4af;color:#9f1239}
.rv-hero{padding:16px 24px;margin-bottom:14px}.rv-hero p{margin-top:0}.rv-hero .rv-kicker{margin-bottom:0}
.rv-delete-modal{position:fixed;inset:0;z-index:400;display:none;align-items:center;justify-content:center;padding:22px}.rv-delete-modal.is-open{display:flex;animation:rvModalIn .18s ease-out}.rv-delete-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.62)}.rv-delete-card{position:relative;width:min(100%,430px);padding:30px;border-radius:16px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.25);text-align:center}.rv-delete-icon{width:52px;height:52px;display:grid;place-items:center;margin:0 auto 14px;border-radius:50%;background:#fff1f2;color:#e11d48;font-size:24px}.rv-delete-close{position:absolute;top:14px;right:14px;width:30px;height:30px;border:0;border-radius:7px;background:#f1f5f9;color:#64748b;cursor:pointer}.rv-delete-close:hover{background:#e2e8f0;color:#0f172a}.rv-delete-card h2{font-family:'Source Serif 4',serif;color:#102a43;font-size:22px}.rv-delete-card p{margin:9px auto 22px;max-width:340px;color:#64748b;font-size:12px;line-height:1.6}.rv-delete-card p strong{color:#334155}.rv-delete-actions{display:flex;justify-content:center;gap:9px}.rv-delete-actions button{border-radius:7px;padding:9px 14px;font:inherit;font-size:11px;font-weight:700;cursor:pointer}.rv-delete-cancel{border:1px solid #cbd5e1;background:#fff;color:#475569}.rv-delete-cancel:hover{background:#f8fafc}.rv-delete-confirm{border:0;background:#e11d48;color:#fff}.rv-delete-confirm:hover{background:#be123c}
.rv-note{max-width:190px;margin-top:4px!important;color:#475569!important;font-style:italic;white-space:normal}
.rv-review-current{font-size:11px;color:#64748b}.rv-review-current b{color:#1e293b}
@media(max-width:560px){.rv-modal-actions{gap:5px}.rv-zoom-tools button[data-zoom="reset"]{display:none}.rv-modal-head{padding:12px}.rv-modal-head h2{font-size:17px}}
</style>
<style>
.rv-body{padding:26px 28px 44px}
.rv-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:16px 24px;margin-bottom:14px;border:1px solid #d6e5ee;border-radius:14px;background:linear-gradient(120deg,#fff 0%,#effaf8 100%);box-shadow:0 10px 28px rgba(15,23,42,.05)}
.rv-kicker{font-size:11px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:#0d9488;margin-bottom:4px}.rv-kicker i{margin-right:5px}.rv-hero p{margin:0;color:#64748b;font-size:13px}
.rv-alert{display:flex;align-items:center;gap:9px;padding:12px 15px;border-radius:9px;margin-bottom:18px;font-size:13px;font-weight:600}.rv-alert.is-success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.rv-alert.is-error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.rv-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.rv-stat{display:flex;align-items:center;gap:12px;padding:15px 16px;background:#fff;border:1px solid #d9e2ef;border-radius:11px;text-decoration:none;box-shadow:0 5px 16px rgba(15,23,42,.035);transition:border-color .15s,transform .15s}.rv-stat:hover{border-color:#5eead4;transform:translateY(-2px)}.rv-stat-icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:19px}.rv-stat-icon.amber{background:#fff7ed;color:#c2410c}.rv-stat-icon.green{background:#ecfdf5;color:#047857}.rv-stat-icon.blue{background:#eff6ff;color:#1d4ed8}.rv-stat-icon.slate{background:#f1f5f9;color:#475569}.rv-stat strong{display:block;font-size:22px;line-height:1.05;color:#102a43}.rv-stat small{display:block;color:#64748b;font-size:11px;margin-top:3px}
.rv-panel{background:#fff;border:1px solid #d9e2ef;border-radius:14px;box-shadow:0 8px 24px rgba(15,23,42,.045);overflow:hidden}.rv-panel-head{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:20px 22px 15px}.rv-panel-head h2{font-family:'Source Serif 4',serif;font-size:22px;color:#102a43;margin:0}.rv-panel-head p{font-size:12px;color:#64748b;margin:2px 0 0}.rv-count{font-size:11px;font-weight:700;color:#0f766e;background:#ccfbf1;padding:6px 10px;border-radius:20px}
.rv-controls{padding:0 22px 14px;border-bottom:1px solid #e6edf4}.rv-search-form{display:flex;align-items:center;gap:9px;max-width:620px;margin-bottom:13px;padding:5px 6px 5px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.rv-search-form>i{font-size:17px;color:#0d9488}.rv-search-form input{flex:1;min-width:0;border:0;outline:0;background:transparent;color:#1e293b;font:inherit;font-size:12px}.rv-search-form button{border:0;border-radius:7px;padding:8px 14px;background:#0d9488;color:#fff;font:inherit;font-size:11px;font-weight:700;cursor:pointer}.rv-clear-search{display:grid;place-items:center;width:25px;height:25px;border-radius:6px;color:#64748b;text-decoration:none}.rv-tabs{display:flex;gap:8px;overflow-x:auto}.rv-tabs a{display:flex;align-items:center;gap:8px;padding:8px 11px;color:#64748b;text-decoration:none;font-size:11px;font-weight:700;border:1px solid #e2e8f0;border-radius:8px;background:#fff;white-space:nowrap}.rv-tabs a.active{color:#fff;background:#0d9488;border-color:#0d9488}.rv-tabs b{min-width:20px;padding:2px 5px;text-align:center;border-radius:10px;font-size:10px;color:#64748b;background:#f1f5f9}.rv-tabs a.active b{color:#0f766e;background:#ccfbf1}
.rv-table-wrap{overflow-x:auto}.rv-table{width:100%;border-collapse:collapse;min-width:1060px}.rv-table th{padding:12px 14px;text-align:left;background:#f8fafc;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.75px;font-weight:700;border-bottom:1px solid #e2e8f0}.rv-table td{padding:14px;border-bottom:1px solid #edf2f7;vertical-align:top;color:#334155;font-size:12px}.rv-table td strong{display:block;color:#1e293b;font-size:12px}.rv-table td small{display:block;font-size:11px;color:#64748b;margin-top:2px}.rv-person{display:flex;align-items:center;gap:9px;min-width:175px}.rv-avatar{width:30px;height:30px;display:grid;place-items:center;border-radius:9px;background:#ccfbf1;color:#0f766e;font-weight:800;font-size:12px}.rv-muted{color:#94a3b8!important}.rv-docs{display:flex;flex-direction:column;gap:5px;min-width:150px}.rv-docs a{display:flex;align-items:center;gap:4px;color:#0f766e;text-decoration:none;font-size:11px;font-weight:700}.rv-status{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap}.rv-status.pending{background:#fff7ed;color:#c2410c}.rv-status.approved{background:#ecfdf5;color:#047857}.rv-status.rejected{background:#fff1f2;color:#be123c}.rv-status.resubmit{background:#eff6ff;color:#1d4ed8}
.rv-empty{display:flex;align-items:center;flex-direction:column;gap:5px;padding:58px 20px;color:#64748b}.rv-empty i{font-size:34px;color:#5eead4}.rv-empty strong{color:#334155;font-size:14px}.rv-empty span{font-size:12px}
@media(max-width:900px){.rv-stats{grid-template-columns:repeat(2,1fr)}.rv-hero{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.rv-body{padding:16px 12px 30px}.rv-stats{gap:8px}.rv-stat{padding:11px}.rv-panel-head{padding:16px}.rv-controls{padding:0 16px 14px}.rv-hero{padding:19px}}
.rv-details{position:relative;display:block;min-width:150px}.rv-details summary{display:inline-flex;align-items:center;gap:5px;cursor:pointer;color:#0f766e;font-size:11px;font-weight:700;list-style:none}.rv-details summary::-webkit-details-marker{display:none}.rv-review-form{position:fixed;z-index:350;display:flex;flex-direction:column;gap:8px;width:min(280px,calc(100vw - 32px));max-height:calc(100vh - 32px);overflow:auto;padding:34px 16px 16px;margin:0;border:1px solid #d9e2ef;border-radius:12px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.25)}.rv-review-form label{font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px}.rv-review-form select,.rv-review-form textarea{display:block;width:100%;margin-top:4px;border:1px solid #cbd5e1;border-radius:6px;padding:7px;font:inherit;font-size:11px;background:#fff;color:#1e293b}.rv-review-form textarea{min-height:58px;resize:vertical}.rv-review-form button{border:0;border-radius:6px;padding:8px 10px;background:#0d9488;color:#fff;font:inherit;font-size:11px;font-weight:700;cursor:pointer}.rv-review-form button:hover{background:#0f766e}.rv-review-form .rv-review-close{position:absolute;top:8px;right:8px;width:25px;height:25px;padding:0;border-radius:6px;background:#e2e8f0;color:#475569}.rv-review-form .rv-review-close:hover{background:#cbd5e1;color:#0f172a}.rv-review-form .rv-save-review{margin-top:2px}
.rv-document-modal{position:fixed;inset:0;z-index:300;display:none;align-items:center;justify-content:center;padding:22px}.rv-document-modal.is-open{display:flex;animation:rvModalIn .18s ease-out}.rv-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(3px)}.rv-modal-card{position:relative;z-index:1;display:flex;flex-direction:column;width:min(920px,100%);height:min(760px,90vh);overflow:hidden;border:1px solid rgba(255,255,255,.3);border-radius:14px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.3)}.rv-modal-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 18px;border-bottom:1px solid #e2e8f0;background:#f8fafc}.rv-modal-body{position:relative;z-index:1;flex:1;display:flex;align-items:center;justify-content:center;overflow:auto;background:#e2e8f0;padding:12px}.rv-modal-body iframe{display:none;width:100%;height:100%;border:0;border-radius:8px;background:#fff}.rv-modal-body iframe.is-visible{display:block}.rv-modal-body img{display:none;max-width:100%;max-height:100%;object-fit:contain}.rv-modal-body img.is-visible{display:block}
.rv-delete-modal{position:fixed;inset:0;z-index:400;display:none;align-items:center;justify-content:center;padding:22px}.rv-delete-modal.is-open{display:flex;animation:rvModalIn .18s ease-out}.rv-delete-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.62)}.rv-delete-card{position:relative;z-index:1;width:min(100%,430px);padding:30px;border-radius:16px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.25);text-align:center}.rv-delete-close{position:absolute;top:14px;right:14px;width:30px;height:30px;border:0;border-radius:7px;background:#f1f5f9;color:#64748b;cursor:pointer}
</style>