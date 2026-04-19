<?php
session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
gabaybatas_block_barangay_delete_requests();

gabaybatas_require_role(['barangay'], '../login.php');

$caseIdParam = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$error = '';
$success = '';
$case = null;

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
$nameColumn     = $hasColumn('name') ? 'name' : ($hasColumn('resident_name') ? 'resident_name' : null);
$complainedColumn = $hasColumn('complained_person') ? 'complained_person' : ($hasColumn('respondent_name') ? 'respondent_name' : ($hasColumn('against_person') ? 'against_person' : null));
$typeColumn     = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn   = $hasColumn('status') ? 'status' : null;
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;
$dateColumn     = $hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('created_at') ? 'created_at' : null);

$noteColumns = [];
foreach (['remarks', 'notes', 'resolution_notes', 'description'] as $candidate) {
	if ($hasColumn($candidate)) {
		$noteColumns[] = $candidate;
	}
}

if ($idColumn === null) {
	$error = 'Cases table is missing an identifier column.';
} elseif ($caseIdParam === '') {
	$error = 'Missing case id.';
} else {
	$selectFields = [];
	if ($displayIdCol)   { $selectFields[] = "$displayIdCol AS display_case_id"; }
	if ($nameColumn)     { $selectFields[] = "$nameColumn AS resident_name"; }
	if ($complainedColumn) { $selectFields[] = "$complainedColumn AS complained_person"; }
	if ($typeColumn)     { $selectFields[] = "$typeColumn AS case_type"; }
	if ($statusColumn)   { $selectFields[] = "$statusColumn AS case_status"; }
	if ($barangayColumn) { $selectFields[] = "$barangayColumn AS case_barangay"; }
	if ($dateColumn)     { $selectFields[] = "$dateColumn AS case_date"; }
	foreach ($noteColumns as $noteColumn) {
		$selectFields[] = "$noteColumn AS case_notes";
		break;
	}

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
		} elseif ($barangayColumn) {
			$currentBarangay = gabaybatas_get_user_barangay($conn);
			if ($currentBarangay !== '' && !gabaybatas_barangay_matches($case, $currentBarangay)) {
				$error = 'You are not authorized to update this case.';
				$case = null;
			}
		}
	}
}

$statusOptions = ['Pending', 'In Progress', 'Resolved'];
$currentStatus = (string)($case['case_status'] ?? 'Pending');
$currentNotes = (string)($case['case_notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $case !== null) {
	$newStatus = trim((string)($_POST['status'] ?? $currentStatus));
	$newNotes  = trim((string)($_POST['notes'] ?? $currentNotes));

	if ($statusColumn && !in_array($newStatus, $statusOptions, true)) {
		$error = 'Please select a valid status.';
	} else {
		$setParts = [];
		$types = '';
		$values = [];

		if ($statusColumn) {
			$setParts[] = "$statusColumn = ?";
			$types .= 's';
			$values[] = $newStatus;
		}

		if ($noteColumns) {
			$setParts[] = $noteColumns[0] . ' = ?';
			$types .= 's';
			$values[] = $newNotes;
		}

		if ($hasColumn('updated_at')) {
			$setParts[] = 'updated_at = NOW()';
		}

		if (!$setParts) {
			$error = 'This case does not have editable fields.';
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
					header('Location: cases.php?updated=1');
					exit();
				} else {
					$error = 'Unable to update this case.';
				}
				$stmt->close();
			}
		}
	}
}
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">
	<div class="topbar">
		<div class="topbar-title">Update Case</div>
		<div class="topbar-right">
			<div class="icon-btn" title="Notifications">
				<i class="uil uil-bell"></i>
			</div>
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

	<div class="page-body uc-body">
		<div class="uc-head">
			<p class="uc-sub">Update the case status and notes for cases assigned to your barangay.</p>
			<a href="cases.php" class="uc-back-btn"><i class="uil uil-arrow-left"></i> Back to Cases</a>
		</div>

		<div class="card-box uc-card">
			<?php if ($error !== ''): ?>
				<div class="uc-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
			<?php endif; ?>

			<?php if ($success !== ''): ?>
				<div class="uc-alert success"><i class="uil uil-check-circle"></i> <?= htmlspecialchars($success) ?></div>
			<?php endif; ?>

			<?php if ($error === '' && $case !== null): ?>
			<?php
			$dateRaw = (string)($case['case_date'] ?? '');
			$dateTs = strtotime($dateRaw);
			$dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : 'N/A');
			$displayCaseId = gabaybatas_format_case_id($case['display_case_id'] ?? '', $caseIdParam);
			?>
			<div class="uc-grid">
				<div class="uc-panel">
					<div class="uc-panel-title">Case Details</div>
					<div class="uc-info"><span>Case ID</span><strong><?= htmlspecialchars($displayCaseId) ?></strong></div>
					<div class="uc-info"><span>Resident</span><strong><?= htmlspecialchars((string)($case['resident_name'] ?? 'N/A')) ?></strong></div>
					<div class="uc-info"><span>Complained Person</span><strong><?= htmlspecialchars((string)($case['complained_person'] ?? 'N/A')) ?></strong></div>
					<div class="uc-info"><span>Type</span><strong><?= htmlspecialchars((string)($case['case_type'] ?? 'N/A')) ?></strong></div>
					<div class="uc-info"><span>Filed</span><strong><?= htmlspecialchars($dateDisplay) ?></strong></div>
				</div>

				<div class="uc-panel">
					<div class="uc-panel-title">Update Form</div>
					<form method="POST" class="uc-form" id="updateCaseForm">
						<input type="hidden" name="id" value="<?= htmlspecialchars($caseIdParam) ?>">
						<div class="uc-field">
							<label>Status</label>
							<select name="status">
								<?php foreach ($statusOptions as $option): ?>
								<option value="<?= htmlspecialchars($option) ?>" <?= $currentStatus === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="uc-field">
							<label>Notes<?= $noteColumns ? '' : ' (read only)' ?></label>
							<textarea name="notes" rows="6" placeholder="Add progress notes or resolution details"><?= htmlspecialchars($currentNotes) ?></textarea>
						</div>
						<div class="uc-actions">
							<a href="cases.php" class="uc-cancel">Cancel</a>
							<button type="submit" class="uc-save" id="saveCaseBtn"><i class="uil uil-save"></i> Save Changes</button>
						</div>
					</form>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<script>
const updateCaseForm = document.getElementById('updateCaseForm');

if (updateCaseForm) {
	let hasConfirmedSubmit = false;

	updateCaseForm.addEventListener('submit', async function (event) {
		if (hasConfirmedSubmit) {
			return;
		}

		event.preventDefault();

		if (window.AdminConfirm) {
			const ok = await window.AdminConfirm.confirm({
				title: 'Save Case Changes?',
				message: 'This will update the case status and notes. Continue?',
				confirmText: 'Save Changes',
				cancelText: 'Cancel'
			});

			if (!ok) {
				return;
			}
		} else if (!window.confirm('This will update the case status and notes. Continue?')) {
			return;
		}

		hasConfirmedSubmit = true;
		updateCaseForm.submit();
	});
}
</script>

<style>
.uc-body{padding:24px 28px 40px;}
.uc-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;}
.uc-sub{font-size:13px;color:#5a6a7e;margin:0;}
.uc-back-btn{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:12.5px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:9px;padding:8px 13px;transition:all .2s;}
.uc-back-btn:hover{background:#dbeafe;}
.uc-card{padding:20px;}
.uc-alert{display:flex;align-items:center;gap:8px;border-radius:10px;padding:10px 12px;font-size:12.5px;margin-bottom:12px;}
.uc-alert.error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c;}
.uc-alert.success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;}
.uc-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:14px;}
.uc-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.uc-panel-title{font-size:14px;font-weight:700;color:#1f2937;margin-bottom:12px;}
.uc-info{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px;}
.uc-info:last-child{border-bottom:none;}
.uc-info span{color:#6b7280;}
.uc-info strong{color:#111827;text-align:right;}
.uc-form{display:flex;flex-direction:column;gap:12px;}
.uc-field{display:flex;flex-direction:column;gap:6px;}
.uc-field label{font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;font-weight:700;}
.uc-field select,.uc-field textarea{width:100%;background:#fafbff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:13px;color:#1f2937;outline:none;resize:vertical;}
.uc-field select:focus,.uc-field textarea:focus{border-color:#3b82f6;}
.uc-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:4px;}
.uc-cancel,.uc-save{display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:12.5px;font-weight:600;text-decoration:none;border-radius:9px;padding:9px 14px;}
.uc-cancel{border:1px solid #e2e8f0;color:#475569;background:#fff;}
.uc-save{border:none;background:#1d4ed8;color:#fff;cursor:pointer;}
.uc-save:hover{background:#1e40af;}
@media(max-width:900px){.uc-body{padding:16px;}.uc-head{flex-direction:column;align-items:flex-start;}.uc-grid{grid-template-columns:1fr;}}
</style>
