<?php
session_start();

require_once '../config/database.php';
require_once '../config/case_types.php';
require_once __DIR__ . '/../includes/barangay_auth.php';

gabaybatas_require_role(['barangay'], '../login.php');
gabaybatas_block_barangay_delete_requests();

$currentBarangay = gabaybatas_get_user_barangay($conn);

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

$residentColumn = $hasColumn('name') ? 'name' : ($hasColumn('resident_name') ? 'resident_name' : null);
$complainedColumn = $hasColumn('complained_person') ? 'complained_person' : ($hasColumn('respondent_name') ? 'respondent_name' : ($hasColumn('against_person') ? 'against_person' : null));
$typeColumn = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn = $hasColumn('status') ? 'status' : null;
$caseIdColumn = $hasColumn('case_id') ? 'case_id' : null;
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;
$dateColumn = $hasColumn('date_submitted') ? 'date_submitted' : null;
$descriptionColumn = $hasColumn('description') ? 'description' : ($hasColumn('details') ? 'details' : ($hasColumn('remarks') ? 'remarks' : ($hasColumn('notes') ? 'notes' : null)));

$schemaError = '';
if ($residentColumn === null || $typeColumn === null) {
    $schemaError = 'Cases table is missing required fields for resident name or case type.';
}

$error = '';
$resident = trim((string)($_POST['resident_name'] ?? ''));
$complainedPerson = trim((string)($_POST['complained_person'] ?? ''));
$caseType = trim((string)($_POST['case_type'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'Pending'));
$description = trim((string)($_POST['description'] ?? ''));

$allowedCaseTypes = gabaybatas_case_types();

if ($caseType === '') {
    $caseType = $allowedCaseTypes[0];
}
$allowedStatuses = ['Pending', 'In Progress', 'Resolved'];
$appTimezone = new DateTimeZone('Asia/Manila');
$filedAtDisplay = (new DateTimeImmutable('now', $appTimezone))->format('M d, Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaError === '') {
    if ($resident === '' || $complainedPerson === '' || $caseType === '') {
        $error = 'Resident name, complained person, and case type are required.';
    } elseif (!in_array($caseType, $allowedCaseTypes, true)) {
        $error = 'Please select a valid case type.';
    } elseif ($statusColumn && !in_array($status, $allowedStatuses, true)) {
        $error = 'Please select a valid status.';
    } else {
        $caseTypeToSave = $caseType;
        $insertColumns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        $insertColumns[] = $residentColumn;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $resident;

        if ($complainedColumn !== null) {
            $insertColumns[] = $complainedColumn;
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $complainedPerson;
        }

        $insertColumns[] = $typeColumn;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $caseTypeToSave;

        if ($statusColumn) {
            $insertColumns[] = $statusColumn;
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $status;
        }

        if ($barangayColumn && $currentBarangay !== '') {
            $insertColumns[] = $barangayColumn;
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $currentBarangay;
        }

        if ($dateColumn) {
            $insertColumns[] = $dateColumn;
            $placeholders[] = '?';
            $types .= 's';
            $values[] = (new DateTimeImmutable('now', $appTimezone))->format('Y-m-d H:i:s');
        }

        if ($descriptionColumn && $description !== '') {
            $insertColumns[] = $descriptionColumn;
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $description;
        }

        $sql = 'INSERT INTO cases (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $error = 'Unable to prepare case insert query.';
        } else {
            $stmt->bind_param($types, ...$values);
            if ($stmt->execute()) {
                if ($caseIdColumn !== null) {
                    $newCaseId = 'C-' . str_pad((string)$stmt->insert_id, 4, '0', STR_PAD_LEFT);
                    $caseIdUpdate = $conn->prepare("UPDATE cases SET {$caseIdColumn} = ? WHERE id = ? LIMIT 1");
                    if ($caseIdUpdate) {
                        $caseRowId = (int)$stmt->insert_id;
                        $caseIdUpdate->bind_param('si', $newCaseId, $caseRowId);
                        $caseIdUpdate->execute();
                        $caseIdUpdate->close();
                    }
                }
                header('Location: cases.php?created=1');
                exit();
            }
            $error = 'Unable to save case. Please try again.';
            $stmt->close();
        }
    }
}
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
            <p class="acs-sub">Create a new case record for your barangay and save it to the database.</p>
            <a href="cases.php" class="acs-back-btn"><i class="uil uil-arrow-left"></i> Back to Cases</a>
        </div>

        <div class="card-box acs-card">
            <?php if ($schemaError !== ''): ?>
                <div class="acs-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($schemaError) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="acs-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($schemaError === ''): ?>
                <form method="POST" class="acs-form" id="addCaseForm">
                    <div class="acs-grid">
                        <div class="acs-field">
                            <label>Resident Name <span>*</span></label>
                            <input type="text" name="resident_name" value="<?= htmlspecialchars($resident) ?>" required>
                        </div>

                        <div class="acs-field">
                            <label>Person Being Complained <span>*</span></label>
                            <input type="text" name="complained_person" value="<?= htmlspecialchars($complainedPerson) ?>" required>
                        </div>

                        <div class="acs-field">
                            <label>Case Type <span>*</span></label>
                            <select name="case_type" required>
                                <?php foreach ($allowedCaseTypes as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $caseType === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="acs-field">
                            <label>Status</label>
                            <select name="status">
                                <?php foreach ($allowedStatuses as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="acs-field">
                            <label>Date Filed</label>
                            <input type="text" value="<?= htmlspecialchars($filedAtDisplay) ?>" readonly>
                        </div>

                        <div class="acs-field acs-field-full">
                            <label>Description</label>
                            <textarea name="description" rows="6" placeholder="Enter case details (optional)"><?= htmlspecialchars($description) ?></textarea>
                        </div>
                    </div>

                    <div class="acs-actions">
                        <a href="cases.php" class="acs-cancel">Cancel</a>
                        <button type="submit" class="acs-save"><i class="uil uil-save"></i> Save Case</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const addCaseForm = document.getElementById('addCaseForm');

if (addCaseForm) {
    let hasConfirmedSubmit = false;

    addCaseForm.addEventListener('submit', async function (event) {
        if (hasConfirmedSubmit) {
            return;
        }

        event.preventDefault();

        if (window.AdminConfirm) {
            const ok = await window.AdminConfirm.confirm({
                title: 'Save New Case?',
                message: 'Please confirm all case information is correct before saving.',
                confirmText: 'Save Case',
                cancelText: 'Review Again'
            });

            if (!ok) {
                return;
            }
        } else if (!window.confirm('Please confirm all case information is correct before saving.')) {
            return;
        }

        hasConfirmedSubmit = true;
        addCaseForm.submit();
    });
}
</script>

<style>
.acs-body{padding:24px 28px 40px;}
.acs-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;}
.acs-sub{font-size:13px;color:#5a6a7e;margin:0;}
.acs-back-btn{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:12.5px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:9px;padding:8px 13px;transition:all .2s;}
.acs-back-btn:hover{background:#dbeafe;}
.acs-card{padding:20px;}
.acs-alert{display:flex;align-items:center;gap:8px;border-radius:10px;padding:10px 12px;font-size:12.5px;margin-bottom:12px;}
.acs-alert.error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c;}
.acs-form{display:flex;flex-direction:column;gap:12px;}
.acs-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.acs-field{display:flex;flex-direction:column;gap:6px;}
.acs-field-full{grid-column:1 / -1;}
.acs-field label{font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;font-weight:700;}
.acs-field label span{color:#dc2626;}
.acs-field input,.acs-field select,.acs-field textarea{width:100%;background:#fafbff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:13px;color:#1f2937;outline:none;resize:vertical;}
.acs-field input:focus,.acs-field select:focus,.acs-field textarea:focus{border-color:#3b82f6;}
.acs-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:6px;}
.acs-cancel,.acs-save{display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:12.5px;font-weight:600;text-decoration:none;border-radius:9px;padding:9px 14px;}
.acs-cancel{border:1px solid #e2e8f0;color:#475569;background:#fff;}
.acs-save{border:none;background:#1d4ed8;color:#fff;cursor:pointer;}
.acs-save:hover{background:#1e40af;}
@media(max-width:900px){.acs-body{padding:16px;}.acs-head{flex-direction:column;align-items:flex-start;}.acs-grid{grid-template-columns:1fr;}}
</style>
