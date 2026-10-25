<?php
/**
 * KP Document Generation Handler
 * Generates KP form DOCX files from templates with case data.
 */
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';
require_once '../config/kp.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/kp_docx_engine.php';
require_once __DIR__ . '/../includes/kp_form_data.php';
gabaybatas_block_barangay_delete_requests();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cases.php');
    exit();
}

$caseId     = (int)($_POST['case_id'] ?? 0);
$formNumber = (int)($_POST['form_number'] ?? 0);
$action     = trim($_POST['action'] ?? '');
$userId     = (int)($_SESSION['user_id'] ?? 0);

if ($caseId <= 0 || $formNumber <= 0) {
    header('Location: cases.php');
    exit();
}

// Load full case data
$stmt = $conn->prepare("SELECT * FROM cases WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $caseId);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$case || !gabaybatas_barangay_matches($case, GABAYBATAS_BARANGAYS[0])) {
    header('Location: cases.php');
    exit();
}

$redirect = "case_process.php?id={$caseId}";

if ($action === 'generate') {
    if (!empty($case['archived_at'])) {
        header("Location: {$redirect}&gen_error=archived");
        exit();
    }

    $status = (string)($case['kp_status'] ?? 'filed');
    $context = kp_load_case_context($conn, $caseId);

    if (in_array($formNumber, [7, 8, 9, 91], true) && !in_array($status, kp_post_acceptance_statuses(), true)) {
        header("Location: {$redirect}&gen_error=not_accepted");
        exit();
    }

    if ($formNumber === 9 && trim((string)($case['complained_person'] ?? '')) === '') {
        header("Location: {$redirect}&gen_error=missing_respondent");
        exit();
    }

    $hearing = null;
    if (in_array($formNumber, [8, 9, 91], true)) {
        // Mediation notice, summons and return refer to the latest mediation hearing.
        $mediationHearings = kp_filter_hearings($context['hearings'], 'mediation');
        $hearing = $mediationHearings ? end($mediationHearings) : null;

        if (!$hearing) {
            header("Location: {$redirect}&gen_error=hearing_required");
            exit();
        }
    }

    if (!in_array($formNumber, kp_available_forms($case, $context), true)) {
        header("Location: {$redirect}&gen_error=not_available");
        exit();
    }

    // Build extra data based on form type
    $extra = [];

    // Get latest hearing for this case (for summons/notice forms)
    $latestHearing = $hearing;
    if (!$latestHearing) {
        $hStmt = $conn->prepare("SELECT * FROM kp_hearings WHERE case_id = ? ORDER BY hearing_date DESC, id DESC LIMIT 1");
        $hStmt->bind_param('i', $caseId);
        $hStmt->execute();
        $latestHearing = $hStmt->get_result()->fetch_assoc();
        $hStmt->close();
    }

    if ($latestHearing) {
        $hDate = strtotime($latestHearing['hearing_date']);
        $hTime = $latestHearing['hearing_time'] ? date('h:i A', strtotime($latestHearing['hearing_time'])) : '';

        $extra['hearing_date']      = $hDate ? date('F j, Y', $hDate) : '';
        $extra['hearing_date_day']  = $hDate ? date('jS', $hDate) : '';
        $extra['hearing_month']     = $hDate ? strtoupper(date('F', $hDate)) : '';
        $extra['hearing_date_year'] = $hDate ? date('Y', $hDate) : '';
        $extra['hearing_date_full'] = $hDate
            ? strtoupper(date('F', $hDate)) . '  ' . date('d, Y', $hDate) . ', ' . strtoupper(date('l', $hDate)) . ($hTime ? ' alas ' . $hTime : '')
            : '';
        $extra['hearing_day']       = $hDate ? date('l', $hDate) : '';
        $extra['hearing_time']      = $hTime;
        $extra['hearing_location']  = trim((string)($latestHearing['location'] ?? ''));

        // For Officer's Return
        $extra['mediation_date_time'] = $hDate
            ? strtoupper(date('F', $hDate)) . ' ' . date('d, Y', $hDate) . ($hTime ? ' at ' . $hTime : '')
            : '';

        // Service date (today)
        $extra['service_day']        = date('jS');
        $extra['service_month_year'] = strtoupper(date('F')) . ' ' . date('Y');
    }

    // Forms 9-A and 10–27 take their data from the recorded proceedings;
    // Forms 7, 8 and 9 keep the data path above unchanged.
    $inputJson = null;
    if (!in_array($formNumber, [7, 8, 9], true)) {
        $input = array_filter([
            'pangkat_member_id' => (int)($_POST['pangkat_member_id'] ?? 0),
            'witness_name'      => trim((string)($_POST['witness_name'] ?? '')),
            'witness_address'   => trim((string)($_POST['witness_address'] ?? '')),
        ]);
        $built = kp_build_form_data($conn, $case, $formNumber, $context, $input);
        if ($built['error'] !== null) {
            header("Location: {$redirect}&gen_error={$built['error']}#documents");
            exit();
        }
        $extra = array_merge($extra, $built['data']);
        if ($built['blocks']) {
            $extra['__blocks'] = $built['blocks'];
        }
        if ($input) {
            $inputJson = json_encode($input, JSON_UNESCAPED_UNICODE);
        }
    }

    // For complainant city/respondent city
    $extra['document_version'] = kp_next_document_version($conn, $caseId, $formNumber);

    // Generate the DOCX
    $filePath = kp_generate_docx($formNumber, $case, $extra);

    if ($filePath) {
        // Save document record to DB
        $documentId = kp_save_document_record($conn, $caseId, $formNumber, $filePath, '', $userId, $inputJson);
        if (!$documentId) {
            error_log("KP document record could not be saved (Form #{$formNumber}, Case #{$caseId}).");
            @unlink($filePath);
            header("Location: {$redirect}&gen_error=1");
            exit();
        }

        // Log timeline event
        $formInfo = kp_form_info($formNumber);
        $formLabel = kp_form_label($formNumber);
        kp_log_event(
            $conn, $caseId,
            $formInfo['stage'] ?? 'intake',
            $case['kp_status'] ?? 'filed',
            'document_generated',
            "KP Form #{$formLabel} generated",
            "Form: {$formInfo['title']}",
            $userId
        );

        header("Location: {$redirect}&generated={$formNumber}#documents");
        exit();
    } else {
        // Template not found or generation failed
        header("Location: {$redirect}&gen_error=1");
        exit();
    }
}

header("Location: {$redirect}");
exit();
