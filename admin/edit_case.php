<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';
require_once '../config/case_types.php';

$caseIdParam = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$error = '';
$toastType = '';
$toastTitle = '';
$toastMessage = '';
$case = null;

// Check if page loaded after successful save
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $toastType = 'success';
    $toastTitle = 'Case Updated';
    $toastMessage = 'Case details were saved successfully.';
}

$caseColumns = [];
$colResult = $conn->query("SHOW COLUMNS FROM cases");
if ($colResult) {
    while ($col = $colResult->fetch_assoc()) {
        $caseColumns[] = $col['Field'];
    }
}

$hasColumn = static function (string $column) use ($caseColumns): bool {
    return in_array($column, $caseColumns, true);
};

$idColumn       = $hasColumn('id') ? 'id' : ($hasColumn('case_id') ? 'case_id' : null);
$displayIdCol   = $hasColumn('case_id') ? 'case_id' : ($hasColumn('id') ? 'id' : null);
$residentColumn = $hasColumn('resident_name') ? 'resident_name' : ($hasColumn('name') ? 'name' : null);
$complainedColumn = $hasColumn('complained_person') ? 'complained_person' : ($hasColumn('respondent_name') ? 'respondent_name' : ($hasColumn('against_person') ? 'against_person' : null));
$typeColumn     = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn   = $hasColumn('status') ? 'status' : null;
$priorityColumn = $hasColumn('priority') ? 'priority' : null;
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;
$dateColumn     = $hasColumn('created_at') ? 'created_at' : ($hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('updated_at') ? 'updated_at' : null));
$descriptionColumn = $hasColumn('description') ? 'description' : ($hasColumn('details') ? 'details' : ($hasColumn('remarks') ? 'remarks' : ($hasColumn('notes') ? 'notes' : null)));

if ($caseIdParam === '') {
    $error = 'Missing case id.';
} elseif ($idColumn === null) {
    $error = 'Case table is missing an identifier column.';
} else {
    $selectFields = [];
    if ($displayIdCol) { $selectFields[] = "$displayIdCol AS display_case_id"; }
    if ($residentColumn) { $selectFields[] = "$residentColumn AS resident_name"; }
    if ($complainedColumn) { $selectFields[] = "$complainedColumn AS complained_person"; }
    if ($typeColumn) { $selectFields[] = "$typeColumn AS case_type"; }
    if ($statusColumn) { $selectFields[] = "$statusColumn AS case_status"; }
    if ($priorityColumn) { $selectFields[] = "$priorityColumn AS case_priority"; }
    if ($barangayColumn) { $selectFields[] = "$barangayColumn AS case_barangay"; }
    if ($descriptionColumn) { $selectFields[] = "$descriptionColumn AS case_description"; }
    if ($dateColumn) { $selectFields[] = "$dateColumn AS case_date"; }

    if (!$selectFields) {
        $error = 'No readable fields found in case table.';
    } else {
        $sql = 'SELECT ' . implode(', ', $selectFields) . " FROM cases WHERE $idColumn = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $caseIdParam);
            $stmt->execute();
            $res = $stmt->get_result();
            $case = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }
        if (!$case) {
            $error = 'Case not found.';
        }
    }
}

$form = [
    'resident_name' => trim((string)($case['resident_name'] ?? '')),
    'complained_person' => trim((string)($case['complained_person'] ?? '')),
    'case_type' => trim((string)($case['case_type'] ?? '')),
    'case_status' => trim((string)($case['case_status'] ?? 'Pending')),
    'case_priority' => trim((string)($case['case_priority'] ?? 'Medium')),
    'case_barangay' => trim((string)($case['case_barangay'] ?? '')),
    'case_description' => trim((string)($case['case_description'] ?? '')),
];

// Normalize barangay to match valid options (case-insensitive)
if ($form['case_barangay'] !== '') {
    $validBarangays = gabaybatas_get_barangays();
    foreach ($validBarangays as $validBrgy) {
        if (strtolower($form['case_barangay']) === strtolower($validBrgy)) {
            $form['case_barangay'] = $validBrgy;
            break;
        }
    }
}

$statusOptions = ['Pending', 'In Progress', 'Resolved'];
$priorityOptions = ['High', 'Medium', 'Low'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $case !== null) {
    $form['resident_name'] = trim((string)($_POST['resident_name'] ?? $form['resident_name']));
    $form['complained_person'] = trim((string)($_POST['complained_person'] ?? $form['complained_person']));
    $form['case_type'] = trim((string)($_POST['case_type'] ?? $form['case_type']));
    $form['case_status'] = trim((string)($_POST['status'] ?? $form['case_status']));
    $form['case_priority'] = trim((string)($_POST['priority'] ?? $form['case_priority']));
    $form['case_barangay'] = trim((string)($_POST['barangay'] ?? $form['case_barangay']));
    $form['case_description'] = trim((string)($_POST['description'] ?? $form['case_description']));

    // Normalize barangay to match valid options (case-insensitive)
    if ($form['case_barangay'] !== '') {
        $validBarangays = gabaybatas_get_barangays();
        foreach ($validBarangays as $validBrgy) {
            if (strtolower($form['case_barangay']) === strtolower($validBrgy)) {
                $form['case_barangay'] = $validBrgy;
                break;
            }
        }
    }

    if ($statusColumn && !in_array($form['case_status'], $statusOptions, true)) {
        $error = 'Please select a valid status.';
    } elseif ($priorityColumn && !in_array($form['case_priority'], $priorityOptions, true)) {
        $error = 'Please select a valid priority.';
    } else {
        $setParts = [];
        $types = '';
        $values = [];

        $current = [
            'resident_name' => trim((string)($case['resident_name'] ?? '')),
            'complained_person' => trim((string)($case['complained_person'] ?? '')),
            'case_type' => trim((string)($case['case_type'] ?? '')),
            'case_status' => trim((string)($case['case_status'] ?? '')),
            'case_priority' => trim((string)($case['case_priority'] ?? '')),
            'case_barangay' => trim((string)($case['case_barangay'] ?? '')),
            'case_description' => trim((string)($case['case_description'] ?? '')),
        ];

        // Normalize barangay in current values to match form values (case-insensitive)
        if ($current['case_barangay'] !== '') {
            $validBarangays = gabaybatas_get_barangays();
            foreach ($validBarangays as $validBrgy) {
                if (strtolower($current['case_barangay']) === strtolower($validBrgy)) {
                    $current['case_barangay'] = $validBrgy;
                    break;
                }
            }
        }

        if ($residentColumn && $form['resident_name'] !== $current['resident_name']) {
            $setParts[] = "$residentColumn = ?";
            $types .= 's';
            $values[] = $form['resident_name'];
        }
        if ($complainedColumn && $form['complained_person'] !== $current['complained_person']) {
            $setParts[] = "$complainedColumn = ?";
            $types .= 's';
            $values[] = $form['complained_person'];
        }
        if ($typeColumn && $form['case_type'] !== $current['case_type']) {
            $setParts[] = "$typeColumn = ?";
            $types .= 's';
            $values[] = $form['case_type'];
        }
        if ($statusColumn && $form['case_status'] !== $current['case_status']) {
            $setParts[] = "$statusColumn = ?";
            $types .= 's';
            $values[] = $form['case_status'];
        }
        if ($priorityColumn && $form['case_priority'] !== $current['case_priority']) {
            $setParts[] = "$priorityColumn = ?";
            $types .= 's';
            $values[] = $form['case_priority'];
        }
        if ($barangayColumn && $form['case_barangay'] !== $current['case_barangay']) {
            $setParts[] = "$barangayColumn = ?";
            $types .= 's';
            $values[] = $form['case_barangay'];
        }
        if ($descriptionColumn && $form['case_description'] !== $current['case_description']) {
            $setParts[] = "$descriptionColumn = ?";
            $types .= 's';
            $values[] = $form['case_description'];
        }
        if ($hasColumn('updated_at')) {
            $setParts[] = 'updated_at = NOW()';
        }

        if (!$setParts || (count($setParts) === 1 && $setParts[0] === 'updated_at = NOW()')) {
            $toastType = 'info';
            $toastTitle = 'No Changes';
            $toastMessage = 'No fields were modified, so nothing was updated.';
        } else {
            $sql = 'UPDATE cases SET ' . implode(', ', $setParts) . " WHERE $idColumn = ?";
            $types .= 's';
            $values[] = $caseIdParam;

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $error = 'Unable to prepare case update query.';
            } else {
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) {
                    $toastType = 'success';
                    $toastTitle = 'Case Updated';
                    $toastMessage = 'Case details were saved successfully.';

                    $case['resident_name'] = $form['resident_name'];
                    $case['complained_person'] = $form['complained_person'];
                    $case['case_type'] = $form['case_type'];
                    $case['case_status'] = $form['case_status'];
                    $case['case_priority'] = $form['case_priority'];
                    $case['case_barangay'] = $form['case_barangay'];
                    $case['case_description'] = $form['case_description'];
                    
                    // Redirect to prevent POST resubmission
                    $stmt->close();
                    header('Location: edit_case.php?id=' . urlencode($caseIdParam) . '&saved=1');
                    exit();
                } else {
                    $error = 'Unable to update this case.';
                    $stmt->close();
                }
            }
        }
    }
}
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <div class="topbar-title">Edit Case</div>
            <span class="topbar-badge">Admin</span>
        </div>
        <div class="topbar-right">
            <div class="notif-btn"><i class="uil uil-bell"></i></div>
            <div class="topbar-sep"></div>
            <div class="user-pill">
                <div class="user-avatar"><i class="uil uil-user"></i></div>
                <div class="user-text">
                    <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></strong>
                    <span>Administrator</span>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body ec-body">
        <div class="ec-head fade-up" style="--d:0s">
            <p class="ec-sub">Update case information, status progression, and administrative details.</p>
            <a href="all_cases.php" class="ec-back-btn"><i class="uil uil-arrow-left"></i> Back to All Cases</a>
        </div>

        <div class="ec-card fade-up" style="--d:.08s">
            <?php if ($error !== ''): ?>
                <div class="ec-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($error === '' && $case !== null): ?>
                <form method="POST" id="editCaseForm" class="ec-form">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($caseIdParam) ?>">

                    <div class="ec-grid">
                        <div class="ec-field">
                            <label>Case ID</label>
                            <input type="text" value="<?= htmlspecialchars((string)($case['display_case_id'] ?? $caseIdParam)) ?>" readonly>
                        </div>
                        <div class="ec-field">
                            <label>Date</label>
                            <input type="text" value="<?= htmlspecialchars((string)($case['case_date'] ?? 'N/A')) ?>" readonly>
                        </div>

                        <div class="ec-field">
                            <label>Resident Name</label>
                            <input type="text" name="resident_name" value="<?= htmlspecialchars($form['resident_name']) ?>" <?= $residentColumn ? '' : 'readonly' ?>>
                        </div>
                        <div class="ec-field">
                            <label>Person Being Complained</label>
                            <input type="text" name="complained_person" value="<?= htmlspecialchars($form['complained_person']) ?>" <?= $complainedColumn ? '' : 'readonly' ?>>
                        </div>
                        <div class="ec-field">
                            <label>Case Type</label>
                            <?php $caseTypes = gabaybatas_case_types(); ?>
                            <?php if ($typeColumn): ?>
                            <select name="case_type">
                                <?php if ($form['case_type'] !== '' && !in_array($form['case_type'], $caseTypes, true)): ?>
                                <option value="<?= htmlspecialchars($form['case_type']) ?>" selected><?= htmlspecialchars($form['case_type']) ?> (Current)</option>
                                <?php endif; ?>
                                <?php foreach ($caseTypes as $caseTypeOption): ?>
                                <option value="<?= htmlspecialchars($caseTypeOption) ?>" <?= $form['case_type'] === $caseTypeOption ? 'selected' : '' ?>><?= htmlspecialchars($caseTypeOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="text" name="case_type" value="<?= htmlspecialchars($form['case_type']) ?>" readonly>
                            <?php endif; ?>
                        </div>

                        <div class="ec-field">
                            <label>Status</label>
                            <select name="status" <?= $statusColumn ? '' : 'disabled' ?>>
                                <?php foreach ($statusOptions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $form['case_status'] === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ec-field">
                            <label>Priority</label>
                            <select name="priority" <?= $priorityColumn ? '' : 'disabled' ?>>
                                <?php foreach ($priorityOptions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $form['case_priority'] === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ec-field ec-field-full">
                            <label>Barangay</label>
                            <select name="barangay" <?= $barangayColumn ? '' : 'disabled' ?>>
                                <option value="">-- Select Barangay --</option>
                                <?php foreach (gabaybatas_get_barangays() as $brgy): ?>
                                <option value="<?= htmlspecialchars($brgy) ?>" <?= $form['case_barangay'] === $brgy ? 'selected' : '' ?>><?= htmlspecialchars($brgy) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ec-field ec-field-full">
                            <label>Description / Notes</label>
                            <textarea name="description" rows="6" <?= $descriptionColumn ? '' : 'readonly' ?>><?= htmlspecialchars($form['case_description']) ?></textarea>
                        </div>
                    </div>

                    <div class="ec-actions">
                        <a href="all_cases.php" class="ec-cancel">Cancel</a>
                        <button type="submit" class="ec-save"><i class="uil uil-save"></i> Save Changes</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const ecObserver = new IntersectionObserver(entries => {
    entries.forEach(en => {
        if (en.isIntersecting) {
            en.target.classList.add('visible');
            ecObserver.unobserve(en.target);
        }
    });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-up').forEach(el => ecObserver.observe(el));

const editCaseForm = document.getElementById('editCaseForm');
if (editCaseForm) {
    let confirmed = false;
    editCaseForm.addEventListener('submit', async function (event) {
        if (confirmed) return;
        event.preventDefault();

        if (window.AdminConfirm) {
            const ok = await window.AdminConfirm.confirm({
                title: 'Save Case Changes?',
                message: 'This will update the selected case details.',
                confirmText: 'Save Changes',
                cancelText: 'Cancel'
            });
            if (!ok) return;
        }

        confirmed = true;
        editCaseForm.submit();
    });
}

<?php if ($toastType !== ''): ?>
if (window.AdminToast) {
    window.AdminToast.show('<?= htmlspecialchars($toastType, ENT_QUOTES) ?>', '<?= htmlspecialchars($toastTitle, ENT_QUOTES) ?>', '<?= htmlspecialchars($toastMessage, ENT_QUOTES) ?>');
}
<?php endif; ?>
</script>

<style>
.ec-body { padding: 24px 28px 40px; }
.fade-up { opacity: 0; transform: translateY(20px); transition: opacity .5s ease var(--d,0s), transform .5s ease var(--d,0s); }
.fade-up.visible { opacity: 1; transform: none; }
.ec-head { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:14px; }
.ec-sub { font-size:12.5px; color:var(--text-sub); margin:0; }
.ec-back-btn { display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-size:12.5px; font-weight:600; color:#1d4ed8; background:#eff6ff; border:1px solid #dbeafe; border-radius:9px; padding:8px 13px; }
.ec-card { background:#fff; border:1px solid #f1f5f9; border-radius:16px; box-shadow:0 1px 4px rgba(0,0,0,.04); padding:20px; }
.ec-alert { display:flex; align-items:center; gap:8px; border-radius:10px; padding:10px 12px; font-size:12.5px; margin-bottom:12px; }
.ec-alert.error { background:#fff1f2; border:1px solid #fecdd3; color:#be123c; }
.ec-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.ec-field { display:flex; flex-direction:column; gap:6px; }
.ec-field label { font-size:11px; letter-spacing:.6px; text-transform:uppercase; color:#6b7280; font-weight:700; }
.ec-field input, .ec-field select, .ec-field textarea { width:100%; background:#fafbff; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; font-size:13px; color:#1f2937; outline:none; }
.ec-field input:focus, .ec-field select:focus, .ec-field textarea:focus { border-color:#3b82f6; }
.ec-field input[readonly], .ec-field textarea[readonly] { background:#f8fafc; color:#64748b; }
.ec-field-full { grid-column:1 / -1; }
.ec-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; }
.ec-cancel, .ec-save { display:inline-flex; align-items:center; justify-content:center; gap:6px; border-radius:9px; padding:9px 14px; font-size:12.5px; font-weight:600; text-decoration:none; }
.ec-cancel { border:1px solid #e2e8f0; color:#475569; background:#fff; }
.ec-save { border:none; background:#1d4ed8; color:#fff; cursor:pointer; }
.ec-save:hover { background:#1e40af; }
@media (max-width:900px) { .ec-body{padding:16px;} .ec-head{flex-direction:column;align-items:flex-start;} .ec-grid{grid-template-columns:1fr;} }
</style>
