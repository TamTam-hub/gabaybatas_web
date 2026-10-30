<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';
require_once '../config/barangays.php';
require_once __DIR__ . '/../includes/case_intake_form.php';

// Same fields, rules and change log as the barangay's Edit Case page
// (barangay/update_case.php); admins may edit cases of any barangay.

$caseIdParam = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$error = '';
$case = null;

if ($caseIdParam === '') {
    $error = 'Missing case id.';
} else {
    // Accepts the row id (56) or the case number (C-0056).
    $stmt = $conn->prepare('SELECT * FROM cases WHERE (id = ? OR case_id = ?) AND archived_at IS NULL LIMIT 1');
    $caseIdInt = ctype_digit($caseIdParam) ? (int)$caseIdParam : 0;
    $stmt->bind_param('is', $caseIdInt, $caseIdParam);
    $stmt->execute();
    $case = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$case) {
        $error = 'Case not found.';
    }
}

$caseId = (int)($case['id'] ?? 0);
$actor = [
    'name' => (string)($_SESSION['name'] ?? 'Admin'),
    'role' => 'Administrator',
    'id'   => (int)($_SESSION['user_id'] ?? 0),
];
$currentStatus = !empty($case['kp_status']) ? (string)$case['kp_status'] : (string)($case['status'] ?? 'Pending');
$statusChoices = $case ? kp_correction_status_options($currentStatus) : [];

$state = gabaybatas_case_edit_state($case ?? []);
$freeText = $state['free_text'];
$form = $state['saved'];
$errors = [];
$statusError = '';
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $case && $action === 'details') {
    $form = gabaybatas_intake_from_post();
    $errors = gabaybatas_case_edit_save($conn, $case, $state, $form, $actor);
    if (!$errors) {
        header('Location: edit_case.php?id=' . $caseId . '&saved=1');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $case && $action === 'status') {
    $statusError = gabaybatas_case_status_correct(
        $conn, $case, trim((string)($_POST['status'] ?? '')), (string)($_POST['change_reason'] ?? ''), $actor
    );
    if ($statusError === '') {
        header('Location: edit_case.php?id=' . $caseId . '&saved=1');
        exit();
    }
}

$documentCount = 0;
if ($case) {
    $dc = $conn->prepare('SELECT COUNT(*) FROM kp_documents WHERE case_id = ?');
    $dc->bind_param('i', $caseId);
    $dc->execute();
    $documentCount = (int)$dc->get_result()->fetch_row()[0];
    $dc->close();
}
$dateTs = strtotime((string)($case['date_submitted'] ?? ''));
$dateDisplay = $dateTs ? date('M d, Y', $dateTs) : 'N/A';
$displayCaseId = (string)($case['case_id'] ?? '') ?: ('C-' . str_pad((string)$caseId, 4, '0', STR_PAD_LEFT));
$statusColor = kp_status_color($currentStatus);
$justSaved = ($_GET['saved'] ?? '') === '1';
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
                    <strong><?= htmlspecialchars($actor['name']) ?></strong>
                    <span>Administrator</span>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body acs-body">
        <div class="acs-head">
            <p class="acs-sub">
                <?php if ($case): ?>
                    Correct the details of <strong><?= htmlspecialchars($displayCaseId) ?></strong>
                    (<?= htmlspecialchars((string)($case['barangay'] ?? '')) ?>). The statement on KP Form No. 7 is rebuilt from your corrections.
                <?php else: ?>
                    Correct the details of a case.
                <?php endif; ?>
            </p>
            <a href="all_cases.php" class="acs-back-btn"><i class="uil uil-arrow-left"></i> Back to Manage Cases</a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="acs-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php else: ?>

            <?php if ($errors): ?>
                <div class="acs-alert error"><i class="uil uil-exclamation-circle"></i>
                    <?= htmlspecialchars($errors['form'] ?? 'Please correct the highlighted fields.') ?>
                </div>
            <?php endif; ?>
            <?php if ($documentCount > 0): ?>
                <div class="acs-alert info"><i class="uil uil-info-circle"></i>
                    KP forms were already generated for this case. After correcting the details, the barangay should generate the forms again so they show the corrected information.
                </div>
            <?php endif; ?>

            <form method="POST" class="acs-form" id="editCaseForm" novalidate>
                <input type="hidden" name="id" value="<?= $caseId ?>">
                <input type="hidden" name="action" value="details">
                <?php gabaybatas_intake_render($form, $errors, [
                    'date_filed'  => $dateDisplay,
                    'free_text'   => $freeText,
                    'status_html' => '<div class="acs-status" style="border-color:' . $statusColor . '55;background:' . $statusColor . '12;color:' . $statusColor . ';">'
                        . '<span class="acs-status-dot" style="background:' . $statusColor . ';"></span> ' . htmlspecialchars(kp_status_label($currentStatus)) . '</div>'
                        . '<small class="acs-hint">The barangay moves the case through Case Process; correct a wrong status below.</small>',
                ]); ?>

                <div class="acs-actions">
                    <a href="all_cases.php" class="acs-cancel">Cancel</a>
                    <button type="submit" class="acs-save"><i class="uil uil-save"></i> Save Corrections</button>
                </div>
            </form>

            <!-- Status correction (for mistakes only) -->
            <form method="POST" class="card-box acs-card uc-status-card" id="statusCaseForm">
                <input type="hidden" name="id" value="<?= $caseId ?>">
                <input type="hidden" name="action" value="status">
                <div class="acs-card-title"><i class="uil uil-exchange"></i> Status Correction</div>
                <p class="acs-sub" style="margin-bottom:12px;">Use this only to correct a wrong status. Hearings, settlements and certifications are recorded by the barangay in Case Process.</p>
                <?php if ($statusError !== ''): ?>
                    <div class="acs-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($statusError) ?></div>
                <?php endif; ?>
                <div class="acs-grid">
                    <div class="acs-field">
                        <label>New Status</label>
                        <select name="status">
                            <?php foreach ($statusChoices as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="acs-hint">Only the next steps allowed by the KP procedure are listed.</small>
                    </div>
                    <div class="acs-field">
                        <label>Reason for the change <span>*</span></label>
                        <textarea name="change_reason" rows="2" placeholder="Required when changing the status"></textarea>
                    </div>
                </div>
                <div class="acs-actions" style="margin-top:12px;">
                    <button type="submit" class="acs-save uc-status-btn"><i class="uil uil-exchange"></i> Change Status</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($case): ?>
<?php gabaybatas_intake_assets('editCaseForm'); ?>
<script>
(function () {
    function confirmBeforeSubmit(form, options, validate) {
        let confirmed = false;
        form.addEventListener('submit', async function (event) {
            if (confirmed) return;
            event.preventDefault();
            if (validate && !form.checkValidity()) {
                form.reportValidity();
                return;
            }
            const ok = window.AdminConfirm
                ? await window.AdminConfirm.confirm(options)
                : window.confirm(options.title + '\n\n' + options.message);
            if (!ok) return;
            confirmed = true;
            form.submit();
        });
    }
    confirmBeforeSubmit(document.getElementById('editCaseForm'), {
        title: 'Save corrections?',
        message: 'The case details and the statement on KP Form No. 7 will be updated. Each change, with its old and new value, is recorded in the case timeline.',
        confirmText: 'Save Corrections',
        cancelText: 'Review Again'
    }, true);
    confirmBeforeSubmit(document.getElementById('statusCaseForm'), {
        title: 'Change the case status?',
        message: 'Use this only to correct a mistake. The change and your reason are recorded in the case timeline.',
        confirmText: 'Change Status',
        cancelText: 'Cancel'
    }, false);

    <?php if ($justSaved): ?>
    if (window.AdminToast) {
        window.AdminToast.show('success', 'Case Updated', 'Your corrections were saved and recorded in the case timeline.');
    }
    const url = new URL(window.location.href);
    url.searchParams.delete('saved');
    window.history.replaceState(null, '', url.pathname + url.search);
    <?php endif; ?>
})();
</script>
<?php endif; ?>

<style>
.uc-status-card{margin-top:22px;border-top:3px solid #f59e0b;}
.uc-status-btn{background:#b45309;}
.uc-status-btn:hover{background:#92400e;}
</style>
