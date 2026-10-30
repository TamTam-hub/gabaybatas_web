<?php
session_start();

$active_page = 'cases';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/pagination.php';
gabaybatas_block_barangay_delete_requests();

$currentBarangay = gabaybatas_get_user_barangay($conn);

// ── Get case data ──
$caseIdParam = trim((string)($_GET['id'] ?? ''));
$case = null;
$error = '';

// ── Case finder (opened from the sidebar without a case) ──
$finderMode = $caseIdParam === '';
$finderQuery = trim((string)($_GET['q'] ?? ''));
$finderCases = [];

if ($finderMode) {
    $hasCaseBarangay = gabaybatas_table_has_column($conn, 'cases', 'barangay');
    $where = ['c.archived_at IS NULL'];
    $types = '';
    $params = [];
    if ($hasCaseBarangay && $currentBarangay !== '') {
        $where[] = 'LOWER(c.barangay) = LOWER(?)';
        $types .= 's';
        $params[] = $currentBarangay;
    }
    if ($finderQuery !== '') {
        // "C-0055", "0055" and "55" all find case C-0055.
        $digits = preg_replace('/^\s*C\s*-\s*/i', '', $finderQuery);
        $where[] = '(c.case_id LIKE ? OR c.name LIKE ? OR c.complained_person LIKE ? OR c.case_type LIKE ?'
            . (ctype_digit($digits) ? ' OR c.id = ?' : '') . ')';
        $like = '%' . $finderQuery . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
        if (ctype_digit($digits)) {
            $types .= 'i';
            $params[] = (int)$digits;
        }
    }
    // One page at a time (includes/pagination.php).
    $finderTotal = 0;
    $countStmt = $conn->prepare('SELECT COUNT(*) FROM cases c WHERE ' . implode(' AND ', $where));
    if ($countStmt) {
        if ($types !== '') {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $finderTotal = (int)$countStmt->get_result()->fetch_row()[0];
        $countStmt->close();
    }
    $finderPerPage = gabaybatas_per_page(10);
    $finderPage = min(max(1, (int)($_GET['page'] ?? 1)), max(1, (int)ceil($finderTotal / $finderPerPage)));
    $finderOffset = ($finderPage - 1) * $finderPerPage;
    $stmt = $conn->prepare(
        'SELECT c.id, c.case_id, c.name, c.complained_person, c.case_type, c.kp_status, c.date_submitted
         FROM cases c WHERE ' . implode(' AND ', $where) . " ORDER BY c.id DESC LIMIT {$finderOffset}, {$finderPerPage}"
    );
    if ($stmt) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $finderCases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare(
        "SELECT c.*, 
                (SELECT COUNT(*) FROM kp_hearings h WHERE h.case_id = c.id) AS hearing_count,
                (SELECT COUNT(*) FROM kp_documents d WHERE d.case_id = c.id) AS document_count,
                (SELECT COUNT(*) FROM kp_timeline_events t WHERE t.case_id = c.id) AS event_count,
                (SELECT COUNT(*) FROM kp_settlements s WHERE s.case_id = c.id) AS settlement_count
         FROM cases c WHERE c.id = ? AND c.archived_at IS NULL LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $caseIdParam);
        $stmt->execute();
        $case = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$case) {
        $error = 'Case not found.';
    }
}

// ── Derived data ──
$kpStatus    = $case['kp_status'] ?? 'filed';
$currentStage = kp_status_stage($kpStatus);
$stages       = kp_stages();
$allStatuses  = kp_statuses();
$statusInfo   = $allStatuses[$kpStatus] ?? null;
$requestedTab = trim((string)($_GET['tab'] ?? ''));
if ($requestedTab === '' && isset($_GET['generated'])) {
    $requestedTab = 'documents';
}

// ── Get review history ──
$reviews = [];
if ($case) {
    $r = $conn->prepare("SELECT * FROM kp_case_reviews WHERE case_id = ? ORDER BY created_at DESC");
    $r->bind_param('i', $case['id']);
    $r->execute();
    $reviews = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
}

// ── Get hearings ──
$hearings = [];
if ($case) {
    $h = $conn->prepare("SELECT * FROM kp_hearings WHERE case_id = ? ORDER BY hearing_date ASC");
    $h->bind_param('i', $case['id']);
    $h->execute();
    $hearings = $h->get_result()->fetch_all(MYSQLI_ASSOC);
    $h->close();
}

// ── Get documents ──
$documents = [];
if ($case) {
    $d = $conn->prepare("SELECT * FROM kp_documents WHERE case_id = ? ORDER BY generated_at DESC, id DESC");
    $d->bind_param('i', $case['id']);
    $d->execute();
    $documents = $d->get_result()->fetch_all(MYSQLI_ASSOC);
    $d->close();
}

// ── Get timeline events ──
$timeline = [];
if ($case) {
    $t = $conn->prepare("SELECT * FROM kp_timeline_events WHERE case_id = ? ORDER BY event_at DESC LIMIT 50");
    $t->bind_param('i', $case['id']);
    $t->execute();
    $timeline = $t->get_result()->fetch_all(MYSQLI_ASSOC);
    $t->close();
}

// ── Get pangkat members ──
$pangkat = [];
if ($case) {
    $p = $conn->prepare("SELECT * FROM kp_pangkat WHERE case_id = ? ORDER BY role DESC, id ASC");
    $p->bind_param('i', $case['id']);
    $p->execute();
    $pangkat = $p->get_result()->fetch_all(MYSQLI_ASSOC);
    $p->close();
}

// ── Get settlements ──
$settlements = [];
if ($case) {
    $s = $conn->prepare("SELECT * FROM kp_settlements WHERE case_id = ? ORDER BY created_at DESC");
    $s->bind_param('i', $case['id']);
    $s->execute();
    $settlements = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
}

// ── Get evidence ──
$evidence = [];
if ($case) {
    $cid = (int)$case['id'];
    $cCode = (string)($case['case_id'] ?? '');
    $evStmt = $conn->prepare("SELECT id, evidence_type, original_filename, mime_type, file_size FROM api_case_evidence WHERE case_id = ? OR case_id = ? ORDER BY id ASC");
    if ($evStmt) {
        $evStmt->bind_param('is', $cid, $cCode);
        $evStmt->execute();
        $evidence = $evStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $evStmt->close();
    }
}

// ── Get executions ──
$executions = [];
if ($case) {
    $ex = $conn->prepare("SELECT * FROM kp_executions WHERE case_id = ? ORDER BY created_at DESC");
    if ($ex) {
        $ex->bind_param('i', $case['id']);
        $ex->execute();
        $executions = $ex->get_result()->fetch_all(MYSQLI_ASSOC);
        $ex->close();
    }
}

// ── Arbitration agreements, repudiations and derived proceeding state ──
$caseContext = $case ? kp_load_case_context($conn, (int)$case['id']) : [];
$agreements   = $caseContext['agreements'] ?? [];
$repudiations = $caseContext['repudiations'] ?? [];
$today        = date('Y-m-d');
$defaultLocation = 'Barangay Hall, Barangay Muñoz, Catbalogan City';

$latestAgreement = $agreements[0] ?? null;

$referral = null;
if ($case && ($case['kp_disposition'] ?? '') === 'not_covered') {
    $refStmt = $conn->prepare("SELECT * FROM kp_referrals WHERE case_id = ? ORDER BY id DESC LIMIT 1");
    if ($refStmt) {
        $refCaseId = (int)$case['id'];
        $refStmt->bind_param('i', $refCaseId);
        $refStmt->execute();
        $referral = $refStmt->get_result()->fetch_assoc() ?: null;
        $refStmt->close();
    }
}
$agreementWindowOpen = $latestAgreement && (int)$latestAgreement['repudiated'] === 0 && $today <= $latestAgreement['repudiation_deadline'];

$activeSettlement = null;
foreach ($settlements as $stlRow) {
    if ((int)$stlRow['repudiated'] === 0) {
        $activeSettlement = $stlRow;
        break;
    }
}
$finalSettlement = ($activeSettlement && !empty($activeSettlement['final_at'])) ? $activeSettlement : null;
$executionLimit  = $finalSettlement ? date('Y-m-d', strtotime($finalSettlement['settlement_date'] . ' +6 months')) : null;
$latestExecution = $executions[0] ?? null;
$hasPangkat      = !empty($pangkat);
$lastAwardHearing = null;
foreach ($hearings as $hRow) {
    if ($hRow['stage'] === 'arbitration' && ($hRow['outcome'] ?? '') === 'Submitted for award') {
        $lastAwardHearing = max((string)$lastAwardHearing, (string)$hRow['hearing_date']);
    }
}

$partyRoleOptions = static function () use ($case): string {
    return '<option value="">— Select —</option>'
        . '<option value="complainant">Complainant — ' . htmlspecialchars((string)($case['name'] ?? '')) . '</option>'
        . '<option value="respondent">Respondent — ' . htmlspecialchars((string)($case['complained_person'] ?? '')) . '</option>';
};

/**
 * Render a list of hearings with an outcome selector for unresolved ones.
 *
 * @param array<string, string> $outcomes outcome key => label
 */
function kp_render_hearings(array $list, int $caseId, string $stage, array $outcomes, bool $canRecord): void
{
    if (empty($list)) {
        return;
    }
    echo '<div class="kp-hearing-list">';
    foreach ($list as $hearing) {
        $ts = strtotime((string)$hearing['hearing_date']);
        echo '<div class="kp-hearing-item">';
        echo '<div class="kp-hearing-date-badge"><span class="kp-hb-day">' . date('d', $ts) . '</span><span class="kp-hb-month">' . date('M', $ts) . '</span></div>';
        echo '<div class="kp-hearing-info">';
        echo '<div class="kp-hearing-title">' . htmlspecialchars((string)($hearing['purpose'] ?: ucfirst($stage) . ' Hearing')) . '</div>';
        echo '<div class="kp-hearing-meta"><span><i class="uil uil-clock"></i> ' . ($hearing['hearing_time'] ? date('h:i A', strtotime((string)$hearing['hearing_time'])) : 'TBD') . '</span>';
        echo '<span><i class="uil uil-map-marker"></i> ' . htmlspecialchars((string)($hearing['location'] ?: 'Barangay Hall')) . '</span></div>';
        if (!empty($hearing['outcome'])) {
            echo '<div class="kp-hearing-outcome"><strong>Outcome:</strong> ' . htmlspecialchars((string)$hearing['outcome']) . '</div>';
        }
        echo '</div>';
        if (empty($hearing['outcome']) && $canRecord) {
            echo '<div class="kp-hearing-actions"><form method="POST" action="kp_hearing.php" style="display:inline;">';
            echo '<input type="hidden" name="case_id" value="' . $caseId . '">';
            echo '<input type="hidden" name="stage" value="' . htmlspecialchars($stage) . '">';
            echo '<input type="hidden" name="hearing_id" value="' . (int)$hearing['id'] . '">';
            echo '<select name="outcome" class="kp-select-sm" required><option value="">Outcome...</option>';
            foreach ($outcomes as $key => $label) {
                echo '<option value="' . htmlspecialchars($key) . '">' . htmlspecialchars($label) . '</option>';
            }
            echo '</select> <button type="submit" name="action" value="record_outcome" class="kp-btn kp-btn-sm" title="Save outcome"><i class="uil uil-save"></i></button>';
            echo '</form></div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/** Render the date/time/location fields of a hearing scheduling form. */
function kp_render_schedule_fields(string $defaultLocation, ?string $min = null, ?string $max = null): void
{
    $min = $min ?? date('Y-m-d');
    echo '<div class="kp-field-row">';
    echo '<div class="kp-field"><label>Hearing Date <span>*</span></label><input type="date" name="hearing_date" required min="' . htmlspecialchars($min) . '"' . ($max ? ' max="' . htmlspecialchars($max) . '"' : '') . '></div>';
    echo '<div class="kp-field"><label>Time <span>*</span></label><input type="time" name="hearing_time" required value="10:00"></div>';
    echo '</div>';
    echo '<div class="kp-field"><label>Location</label><input type="text" name="location" value="' . htmlspecialchars($defaultLocation) . '"></div>';
}

// ── Deadline warnings ──
$deadlineWarning = '';
if ($case) {
    $now = new DateTime();
    if ($case['mediation_deadline'] && in_array($kpStatus, ['accepted','mediation_scheduled','mediation_ongoing'])) {
        $dl = new DateTime($case['mediation_deadline']);
        $diff = $now->diff($dl);
        if ($dl < $now) {
            $deadlineWarning = 'Mediation period has EXPIRED (' . $diff->days . ' days overdue)';
        } elseif ($diff->days <= 3) {
            $deadlineWarning = 'Mediation deadline in ' . $diff->days . ' day(s)!';
        }
    }
    if ($case['conciliation_deadline'] && in_array($kpStatus, ['conciliation_scheduled','conciliation_ongoing'])) {
        $dl = new DateTime($case['conciliation_deadline']);
        $diff = $now->diff($dl);
        if ($dl < $now) {
            $deadlineWarning = 'Conciliation period has EXPIRED (' . $diff->days . ' days overdue)';
        } elseif ($diff->days <= 3) {
            $deadlineWarning = 'Conciliation deadline in ' . $diff->days . ' day(s)!';
        }
    }
}

$caseDisplayId = $case['case_id'] ?? ('C-' . str_pad((string)($case['id'] ?? 0), 4, '0', STR_PAD_LEFT));
$dateTs = strtotime($case['date_submitted'] ?? '');
$dateDisplay = $dateTs ? date('M d, Y h:i A', $dateTs) : 'N/A';
$generationError = [
    'archived' => 'Archived cases are view-only. No new document version can be generated.',
    'not_accepted' => 'The complaint must be accepted for KP processing before this form can be generated.',
    'missing_respondent' => 'A respondent is required before the summons can be generated.',
    'hearing_required' => 'Schedule a hearing first before generating this hearing or service document.',
    'not_available' => 'This form is not applicable at the current point of the proceedings.',
    'member_required' => 'Choose the Pangkat member to whom the notice is addressed.',
    'witness_required' => 'Enter the name of the witness to be subpoenaed.',
    'record_missing' => 'The proceeding record this form is based on was not found.',
][(string)($_GET['gen_error'] ?? '')] ?? '';
$actionError = [
    'missing_date'              => 'Please provide the hearing date.',
    'invalid_stage'             => 'This action is not available at the current stage of the case.',
    'invalid_transition'        => 'This step is not allowed from the current status under the KP procedure.',
    'invalid_input'             => 'Please complete all required fields with valid values (dates cannot be in the future).',
    'invalid_hearing'           => 'That hearing was not found or already has a recorded outcome.',
    'invalid_outcome'           => 'Please choose a valid outcome for this hearing.',
    'pangkat_required'          => 'Constitute the Pangkat ng Tagapagkasundo first.',
    'pangkat_incomplete'        => 'Enter all three Pangkat members and how each was chosen.',
    'pangkat_officers'          => 'Select a Chairman and a different Secretary from among the three members.',
    'agreement_required'        => 'Record the Agreement for Arbitration first.',
    'agreement_window_open'     => 'The arbitration hearing can only be held after the 5-day period to repudiate the agreement has ended.',
    'repudiation_period_lapsed' => 'The sworn repudiation must be dated within the repudiation period.',
    'award_hearing_required'    => 'Record an arbitration hearing with the outcome "Submitted for award" first.',
    'award_too_early'           => 'The award may only be made after the 5-day period to repudiate the agreement, and not before the last arbitration hearing.',
    'coverage_reason'           => 'Choose the legal ground for excluding the complaint (and explain it in the notes if you chose "Other").',
    'finality_period_open'      => 'Finality can only be confirmed after the 10-day period has ended.',
    'execution_period_lapsed'   => 'More than 6 months have passed since the settlement; it can no longer be executed by the Lupon.',
    'execution_hearing_date'    => 'The hearing on the motion must be set no later than 5 days after the motion was filed.',
    'compliance_period_open'    => 'The Notice of Execution may only be issued after the 5-day voluntary compliance period.',
    'extension_not_allowed'     => 'The conciliation period can be extended only once, with a stated reason.',
][(string)($_GET['kp_error'] ?? '')] ?? '';
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-title">Case Process</div>
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

    <div class="page-body kp-body">
        <?php if ($finderMode): ?>
            <div class="kp-section-card">
                <h3 class="kp-section-title">Find a Case</h3>
                <p class="kp-section-desc">Search by case number, complainant, respondent or case type, then open the case to continue its process.</p>
                <form method="GET" action="case_process.php" class="kp-finder-form">
                    <div class="kp-field kp-finder-input">
                        <input type="text" name="q" value="<?= htmlspecialchars($finderQuery) ?>" placeholder="e.g. C-0055, Albos, Noise Complaint" autofocus>
                    </div>
                    <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-search"></i> Search</button>
                    <?php if ($finderQuery !== ''): ?>
                        <a href="case_process.php" class="kp-btn kp-btn-ghost">Clear</a>
                    <?php endif; ?>
                </form>
                <div class="kp-finder-meta">
                    <span>
                        <?= $finderTotal ?> case<?= $finderTotal === 1 ? '' : 's' ?><?= $finderQuery !== '' ? ' found' : '' ?>
                    </span>
                </div>

                <?php if (!$finderCases): ?>
                    <div class="kp-finder-empty">
                        <i class="uil uil-folder-open"></i>
                        <p><?= $finderQuery !== '' ? 'No case matches "' . htmlspecialchars($finderQuery) . '".' : 'No cases to show.' ?></p>
                    </div>
                <?php else: ?>
                    <div class="kp-finder-list">
                        <?php foreach ($finderCases as $row):
                            $rowStatus = (string)($row['kp_status'] ?: 'filed');
                            $rowColor = kp_status_color($rowStatus);
                            $rowCode = $row['case_id'] ?: ('C-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT));
                        ?>
                        <a class="kp-finder-row" href="case_process.php?id=<?= (int)$row['id'] ?>">
                            <div class="kp-finder-code"><?= htmlspecialchars($rowCode) ?></div>
                            <div class="kp-finder-parties">
                                <strong><?= htmlspecialchars($row['name'] ?: '—') ?></strong>
                                <span>vs. <?= htmlspecialchars($row['complained_person'] ?: '—') ?> · <?= htmlspecialchars($row['case_type'] ?: 'Unspecified') ?></span>
                            </div>
                            <span class="kp-status-badge" style="background: <?= $rowColor ?>15; color: <?= $rowColor ?>; border: 1px solid <?= $rowColor ?>30;">
                                <?= htmlspecialchars(kp_status_label($rowStatus)) ?>
                            </span>
                            <i class="uil uil-angle-right kp-finder-arrow"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php gabaybatas_pagination($finderTotal, $finderPage, $finderPerPage, ['q' => $finderQuery], 'cases'); ?>
                <?php endif; ?>
            </div>
        <?php elseif ($error !== ''): ?>
            <div class="kp-error-card">
                <i class="uil uil-exclamation-circle"></i>
                <div class="kp-error-title"><?= htmlspecialchars($error) ?></div>
                <a href="case_process.php" class="kp-back-link">Search for another case</a>
            </div>
        <?php else: ?>

        <!-- ─── Back Button & Case Header ─── -->
        <div class="kp-page-head">
            <a href="cases.php" class="kp-back-btn"><i class="uil uil-arrow-left"></i> Back to Cases</a>
            <div class="kp-case-header">
                <div class="kp-case-id"><?= htmlspecialchars($caseDisplayId) ?></div>
                <span class="kp-status-badge" style="background: <?= kp_status_color($kpStatus) ?>15; color: <?= kp_status_color($kpStatus) ?>; border: 1px solid <?= kp_status_color($kpStatus) ?>30;">
                    <?= htmlspecialchars(kp_status_label($kpStatus)) ?>
                </span>
            </div>
        </div>

        <?php if ($deadlineWarning): ?>
        <div class="kp-deadline-alert">
            <i class="uil uil-exclamation-triangle"></i>
            <span><?= htmlspecialchars($deadlineWarning) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($generationError !== ''): ?>
        <div class="kp-error-card" role="alert">
            <i class="uil uil-file-exclamation"></i>
            <div class="kp-error-title"><?= htmlspecialchars($generationError) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($actionError !== ''): ?>
        <div class="kp-error-card" role="alert">
            <i class="uil uil-exclamation-circle"></i>
            <div class="kp-error-title"><?= htmlspecialchars($actionError) ?></div>
        </div>
        <?php endif; ?>

        <!-- ─── Stage Progress Bar ─── -->
        <div class="kp-stage-bar">
            <?php
            $stageOrder = $stages[$currentStage]['order'] ?? 1;
            foreach ($stages as $stageKey => $stageData):
                $sClass = '';
                if ($stageData['order'] < $stageOrder) $sClass = 'completed';
                elseif ($stageData['order'] === $stageOrder) $sClass = 'active';
                else $sClass = 'upcoming';
            ?>
            <div class="kp-stage-item <?= $sClass ?>">
                <div class="kp-stage-dot"><i class="uil <?= htmlspecialchars($stageData['icon']) ?>"></i></div>
                <span class="kp-stage-label"><?= $stageData['label'] ?></span>
            </div>
            <?php if ($stageData['order'] < count($stages)): ?>
            <div class="kp-stage-line <?= $stageData['order'] < $stageOrder ? 'completed' : '' ?>"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- ─── Main Layout: Case Info + Tabs ─── -->
        <div class="kp-layout">

            <!-- Left Panel: Case Summary -->
            <div class="kp-sidebar-panel">
                <div class="kp-panel-card">
                    <div class="kp-panel-title">Case Summary</div>
                    <div class="kp-info-row">
                        <span class="kp-info-label">Complainant</span>
                        <span class="kp-info-value"><?= htmlspecialchars($case['name'] ?? 'N/A') ?></span>
                    </div>
                    <?php if (!empty($case['complainant_address'])): ?>
                    <div class="kp-info-row">
                        <span class="kp-info-label">Address</span>
                        <span class="kp-info-value kp-info-sm"><?= htmlspecialchars($case['complainant_address']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="kp-divider"></div>
                    <div class="kp-info-row">
                        <span class="kp-info-label">Respondent</span>
                        <span class="kp-info-value"><?= htmlspecialchars($case['complained_person'] ?? 'N/A') ?></span>
                    </div>
                    <?php if (!empty($case['respondent_address'])): ?>
                    <div class="kp-info-row">
                        <span class="kp-info-label">Address</span>
                        <span class="kp-info-value kp-info-sm"><?= htmlspecialchars($case['respondent_address']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="kp-divider"></div>
                    <div class="kp-info-row">
                        <span class="kp-info-label">Case Type</span>
                        <span class="kp-info-value"><?= htmlspecialchars($case['case_type'] ?? 'N/A') ?></span>
                    </div>
                    <?php if (in_array($kpStatus, kp_post_acceptance_statuses(), true)): ?>
                    <div class="kp-info-row">
                        <span class="kp-info-label">Nature</span>
                        <form method="POST" action="kp_review.php" class="kp-info-value" style="display:flex;gap:4px;align-items:center;">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="set_nature">
                            <select name="kp_nature" class="kp-select-sm" onchange="this.form.submit()" title="Nature of case for the monthly report">
                                <?php foreach (kp_case_natures() as $natureKey => $natureLabel): ?>
                                <option value="<?= $natureKey ?>" <?= kp_case_nature($case) === $natureKey ? 'selected' : '' ?>><?= $natureLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php endif; ?>
                    <div class="kp-info-row">
                        <span class="kp-info-label">Date Filed</span>
                        <span class="kp-info-value"><?= htmlspecialchars($dateDisplay) ?></span>
                    </div>
                    <?php if ($case['mediation_deadline']): ?>
                    <div class="kp-info-row">
                        <span class="kp-info-label">Mediation Deadline</span>
                        <span class="kp-info-value kp-info-warn"><?= date('M d, Y', strtotime($case['mediation_deadline'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="kp-panel-card">
                    <div class="kp-panel-title">Quick Actions</div>
                    <div class="kp-quick-actions">
                        <a href="view_case.php?id=<?= urlencode($caseIdParam) ?>" class="kp-action-btn kp-action-view">
                            <i class="uil uil-eye"></i> View Full Details
                        </a>
                        <a href="update_case.php?id=<?= urlencode($caseIdParam) ?>" class="kp-action-btn kp-action-edit">
                            <i class="uil uil-edit"></i> Edit Case Info
                        </a>
                    </div>
                </div>

                <!-- Stats Card -->
                <div class="kp-panel-card">
                    <div class="kp-panel-title">Statistics</div>
                    <div class="kp-stats-grid">
                        <div class="kp-stat"><span class="kp-stat-num"><?= (int)($case['hearing_count'] ?? 0) ?></span><span class="kp-stat-label">Hearings</span></div>
                        <div class="kp-stat"><span class="kp-stat-num"><?= (int)($case['document_count'] ?? 0) ?></span><span class="kp-stat-label">Documents</span></div>
                        <div class="kp-stat"><span class="kp-stat-num"><?= (int)($case['event_count'] ?? 0) ?></span><span class="kp-stat-label">Events</span></div>
                        <div class="kp-stat"><span class="kp-stat-num"><?= (int)($case['settlement_count'] ?? 0) ?></span><span class="kp-stat-label">Settlements</span></div>
                    </div>
                </div>

                <!-- Evidence Card -->
                <div class="kp-panel-card">
                    <div class="kp-panel-title" style="display:flex;justify-content:space-between;align-items:center;">
                        <span><i class="uil uil-paperclip"></i> Submitted Evidence</span>
                        <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:#f1f5f9;color:#475569;"><?= count($evidence) ?></span>
                    </div>
                    <?php if (empty($evidence)): ?>
                        <div style="font-size:12px;color:#94a3b8;font-style:italic;padding:6px 0;"><i class="uil uil-file-slash"></i> No evidence files attached.</div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                            <?php foreach ($evidence as $ev): ?>
                                <?php
                                $isVid = strtolower((string)$ev['evidence_type']) === 'video';
                                $szMb = number_format(((int)$ev['file_size']) / (1024 * 1024), 2);
                                ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                                    <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                                        <i class="uil <?= $isVid ? 'uil-video' : 'uil-image' ?>" style="font-size:18px;color:<?= $isVid ? '#9333ea' : '#2563eb' ?>;"></i>
                                        <div style="min-width:0;">
                                            <div style="font-size:12px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;" title="<?= htmlspecialchars((string)$ev['original_filename']) ?>"><?= htmlspecialchars((string)$ev['original_filename']) ?></div>
                                            <div style="font-size:10px;color:#64748b;"><?= $isVid ? 'Video' : 'Photo' ?> &bull; <?= $szMb ?>MB</div>
                                        </div>
                                    </div>
                                    <a href="../api/v1/case_evidence.php?id=<?= (int)$ev['id'] ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:600;color:#2563eb;text-decoration:none;padding:3px 8px;background:#eff6ff;border-radius:5px;border:1px solid #bfdbfe;white-space:nowrap;">
                                        <i class="uil uil-eye"></i> View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Panel: Tabbed Content -->
            <div class="kp-main-panel">
                <!-- Tab Bar -->
                <div class="kp-tabs">
                    <button class="kp-tab active" data-tab="overview"><i class="uil uil-clipboard-alt"></i> Overview</button>
                    <button class="kp-tab" data-tab="review"><i class="uil uil-file-search-alt"></i> Review</button>
                    <button class="kp-tab" data-tab="mediation"><i class="uil uil-balance-scale"></i> Mediation</button>
                    <button class="kp-tab" data-tab="pangkat"><i class="uil uil-users-alt"></i> Pangkat</button>
                    <button class="kp-tab" data-tab="conciliation"><i class="uil uil-comments-alt" aria-hidden="true"></i> Conciliation</button>
                    <button class="kp-tab" data-tab="settlement"><i class="uil uil-file-check-alt"></i> Settlement</button>
                    <button class="kp-tab" data-tab="arbitration"><i class="uil uil-user-check" aria-hidden="true"></i> Arbitration</button>
                    <button class="kp-tab" data-tab="compliance"><i class="uil uil-check-circle"></i> Compliance</button>
                    <button class="kp-tab" data-tab="execution"><i class="uil uil-bolt"></i> Execution</button>
                    <button class="kp-tab" data-tab="disposition"><i class="uil uil-archive"></i> Disposition</button>
                    <button class="kp-tab" data-tab="documents"><i class="uil uil-file-download-alt"></i> Documents</button>
                    <button class="kp-tab" data-tab="timeline"><i class="uil uil-clock-three"></i> Timeline</button>
                </div>

                <!-- ═══ TAB: Overview ═══ -->
                <div class="kp-tab-content <?= $requestedTab === 'overview' || $requestedTab === '' ? 'active' : '' ?>" id="tab-overview">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Current Status</h3>
                        <div class="kp-current-status">
                            <span class="kp-big-badge" style="background: <?= kp_status_color($kpStatus) ?>15; color: <?= kp_status_color($kpStatus) ?>;">
                                <?= htmlspecialchars(kp_status_label($kpStatus)) ?>
                            </span>
                            <span class="kp-stage-text">Stage: <?= htmlspecialchars($stages[$currentStage]['label'] ?? $currentStage) ?></span>
                        </div>
                    </div>

                    <!-- What to do next -->
                    <div class="kp-section-card kp-next-steps">
                        <h3 class="kp-section-title"><i class="uil uil-arrow-circle-right"></i> What To Do Next</h3>
                        <div class="kp-next-body">
                            <?php if ($kpStatus === 'filed'): ?>
                                <p>This complaint has been filed and needs to be <strong>reviewed</strong>.</p>
                                <p>Go to the <strong>Review</strong> tab to screen this complaint and decide whether to accept it for KP processing.</p>
                                <button class="kp-next-action" onclick="switchTab('review')"><i class="uil uil-file-search-alt"></i> Go to Review Tab</button>

                            <?php elseif ($kpStatus === 'under_review'): ?>
                                <p>This complaint is under review. Complete the review in the <strong>Review</strong> tab.</p>
                                <button class="kp-next-action" onclick="switchTab('review')"><i class="uil uil-file-search-alt"></i> Complete Review</button>

                            <?php elseif ($kpStatus === 'additional_info_required'): ?>
                                <p>Additional information was requested. Once received, go to the <strong>Review</strong> tab to update the decision.</p>
                                <button class="kp-next-action" onclick="switchTab('review')"><i class="uil uil-file-search-alt"></i> Update Review</button>

                            <?php elseif ($kpStatus === 'accepted'): ?>
                                <p>This complaint has been accepted for KP processing. Next steps:</p>
                                <ol>
                                    <li>Generate <strong>KP Form #7</strong> (Complaint)</li>
                                    <li>Schedule the <strong>mediation hearing</strong> and issue the summons on the next working day after the complaint is received</li>
                                    <li>After scheduling, generate <strong>KP Form #8</strong> (Notice of Hearing) and <strong>KP Form #9</strong> (Summons)</li>
                                </ol>
                                <div class="kp-action-row">
                                    <button class="kp-next-action" onclick="switchTab('documents')"><i class="uil uil-file-alt"></i> Generate Form #7</button>
                                    <button class="kp-next-action" onclick="switchTab('mediation')"><i class="uil uil-balance-scale"></i> Schedule Mediation</button>
                                </div>

                            <?php elseif (in_array($kpStatus, ['mediation_scheduled', 'mediation_ongoing'])): ?>
                                <p>Mediation is <?= $kpStatus === 'mediation_scheduled' ? 'scheduled' : 'ongoing' ?>. Record each hearing's outcome in the <strong>Mediation</strong> tab: settled, adjourned, failed, or a party absent.</p>
                                <p>If the parties agree in writing to arbitration, record it in the <strong>Arbitration</strong> tab.</p>
                                <button class="kp-next-action" onclick="switchTab('mediation')"><i class="uil uil-balance-scale"></i> Go to Mediation</button>

                            <?php elseif ($kpStatus === 'mediation_settled' || $kpStatus === 'conciliation_settled'): ?>
                                <p>The parties reached a settlement. Record the terms in the <strong>Settlement</strong> tab, then generate <strong>KP Form #16</strong>. The 10-day repudiation period starts on the settlement date.</p>
                                <button class="kp-next-action" onclick="switchTab('settlement')"><i class="uil uil-file-check-alt"></i> Record Settlement</button>

                            <?php elseif (in_array($kpStatus, ['mediation_noshow_complainant', 'conciliation_noshow_complainant', 'conciliation_noshow_respondent'], true)): ?>
                                <?php $absentParty = $kpStatus === 'conciliation_noshow_respondent' ? 'respondent' : 'complainant'; ?>
                                <p>The <?= $absentParty ?> failed to appear. Schedule a hearing for the <?= $absentParty ?> to explain the absence and generate <strong>KP Form #<?= $absentParty === 'complainant' ? '18' : '19' ?></strong>.</p>
                                <?php if ($absentParty === 'complainant'): ?>
                                <p>If the absence is found unjustified, the complaint is dismissed and a Certification to Bar Action (KP Form #21) is issued.</p>
                                <?php else: ?>
                                <p>If the absence is found unjustified, the complainant may file in court (KP Form #20-B) and the respondent's counterclaim is barred (KP Form #22).</p>
                                <?php endif; ?>
                                <button class="kp-next-action" onclick="switchTab('<?= kp_status_stage($kpStatus) ?>')"><i class="uil uil-calendar-alt"></i> Schedule Explanation Hearing</button>

                            <?php elseif ($kpStatus === 'pangkat_constitution'): ?>
                                <p>Mediation failed. Within 15 days, call the parties to constitute the <strong>Pangkat ng Tagapagkasundo</strong>:</p>
                                <ol>
                                    <li>Schedule the constitution meeting and generate <strong>KP Form #10</strong></li>
                                    <li>Record the 3 members chosen by the parties (or by drawing of lots) and the elected Chairman and Secretary</li>
                                    <li>Generate <strong>KP Form #11</strong> for each chosen member, then schedule conciliation — the Pangkat meets within 3 days of its constitution</li>
                                </ol>
                                <button class="kp-next-action" onclick="switchTab('pangkat')"><i class="uil uil-users-alt"></i> Go to Pangkat</button>

                            <?php elseif (in_array($kpStatus, ['conciliation_scheduled', 'conciliation_ongoing'])): ?>
                                <p>Conciliation is <?= $kpStatus === 'conciliation_scheduled' ? 'scheduled' : 'ongoing' ?>. Record each hearing's outcome in the <strong>Conciliation</strong> tab and generate <strong>KP Form #12</strong> for each hearing.</p>
                                <button class="kp-next-action" onclick="switchTab('conciliation')"><i class="uil uil-comments-alt"></i> Go to Conciliation</button>

                            <?php elseif ($kpStatus === 'arbitration_agreed'): ?>
                                <p>The parties signed an Agreement for Arbitration (KP Form #14). Either party may repudiate it until <strong><?= $latestAgreement ? date('M d, Y', strtotime($latestAgreement['repudiation_deadline'])) : '—' ?></strong>. The arbitration hearing can be held after that date.</p>
                                <button class="kp-next-action" onclick="switchTab('arbitration')"><i class="uil uil-user-check"></i> Go to Arbitration</button>

                            <?php elseif ($kpStatus === 'arbitration_hearing'): ?>
                                <p>Arbitration hearings are in progress. Once the parties have presented their case, mark the hearing <strong>Submitted for award</strong>. The award (KP Form #15) is made after the 5-day period to repudiate the agreement and within 10 days thereafter.</p>
                                <button class="kp-next-action" onclick="switchTab('arbitration')"><i class="uil uil-user-check"></i> Go to Arbitration</button>

                            <?php elseif ($kpStatus === 'arbitration_awarded' || $kpStatus === 'settlement_repudiation'): ?>
                                <?php $periodEnd = $activeSettlement ? $activeSettlement['repudiation_deadline'] : null; ?>
                                <?php if ($kpStatus === 'arbitration_awarded'): ?>
                                <p>The arbitration award was rendered. It becomes final and executory 10 days after the award unless a petition to nullify it is filed in court.</p>
                                <?php else: ?>
                                <p>The settlement was recorded. Either party may repudiate it by a sworn statement (KP Form #17) on the ground of fraud, violence or intimidation.</p>
                                <?php endif; ?>
                                <p>Period ends: <strong><?= $periodEnd ? date('M d, Y', strtotime($periodEnd)) : '—' ?></strong>. After that date, confirm finality in the <strong>Settlement</strong> tab.</p>
                                <button class="kp-next-action" onclick="switchTab('settlement')"><i class="uil uil-file-check-alt"></i> Go to Settlement</button>

                            <?php elseif ($kpStatus === 'settlement_final'): ?>
                                <p>The settlement/award is <strong>final and executory</strong>. Begin compliance monitoring.</p>
                                <button class="kp-next-action" onclick="switchTab('compliance')"><i class="uil uil-check-circle"></i> Go to Compliance</button>

                            <?php elseif ($kpStatus === 'compliance_monitoring'): ?>
                                <p>Monitor compliance. If the obliged party does not comply, the other party may file a Motion for Execution (KP Form #23) until <strong><?= $executionLimit ? date('M d, Y', strtotime($executionLimit)) : '—' ?></strong> (6 months from the settlement date).</p>
                                <button class="kp-next-action" onclick="switchTab('compliance')"><i class="uil uil-check-circle"></i> Go to Compliance</button>

                            <?php elseif ($kpStatus === 'execution_pending'): ?>
                                <p>A Motion for Execution was filed. Hear the motion within 5 days (KP Form #24). If there is no voluntary compliance within 5 days after the hearing, issue the Notice of Execution (KP Form #25).</p>
                                <button class="kp-next-action" onclick="switchTab('execution')"><i class="uil uil-bolt"></i> Go to Execution</button>

                            <?php elseif ($kpStatus === 'execution_completed'): ?>
                                <p>Execution is complete. Close the case in the <strong>Execution</strong> tab.</p>
                                <button class="kp-next-action" onclick="switchTab('execution')"><i class="uil uil-bolt"></i> Go to Execution</button>

                            <?php elseif (in_array($kpStatus, kp_terminal_statuses(), true)): ?>
                                <p>This case is <strong>concluded</strong>. No further action required.</p>
                                <?php if (!empty($case['kp_disposition'])): ?>
                                <p>Disposition: <strong><?= htmlspecialchars(kp_disposition_label($case['kp_disposition'])) ?></strong></p>
                                <?php endif; ?>

                            <?php else: ?>
                                <p>Continue processing this case in the relevant tab above.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Case Description -->
                    <?php if (!empty($case['description'])): ?>
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Complaint Narrative</h3>
                        <div class="kp-narrative"><?= nl2br(htmlspecialchars($case['description'])) ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent Timeline -->
                    <?php if (!empty($timeline)): ?>
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Recent Activity</h3>
                        <div class="kp-mini-timeline">
                            <?php foreach (array_slice($timeline, 0, 5) as $evt): ?>
                            <div class="kp-tl-item">
                                <div class="kp-tl-dot"></div>
                                <div class="kp-tl-content">
                                    <div class="kp-tl-title"><?= htmlspecialchars($evt['title']) ?></div>
                                    <div class="kp-tl-time"><?= date('M d, Y h:i A', strtotime($evt['event_at'])) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($timeline) > 5): ?>
                        <button class="kp-see-all" onclick="switchTab('timeline')">See all events →</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ═══ TAB: Review ═══ -->
                <div class="kp-tab-content" id="tab-review">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Intake Review & Screening</h3>

                        <?php if (in_array($kpStatus, ['filed', 'under_review', 'additional_info_required'])): ?>
                        <p class="kp-section-desc">Review this complaint and decide how to proceed. All decisions are logged in the timeline.</p>

                        <form method="POST" action="kp_review.php" class="kp-review-form" id="reviewForm">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">

                            <div class="kp-field">
                                <label>Decision <span>*</span></label>
                                <select name="decision" id="reviewDecision" required>
                                    <option value="">— Select Decision —</option>
                                    <option value="under_review">Mark as Under Review</option>
                                    <option value="accepted">Accept for KP Processing</option>
                                    <option value="additional_info_required">Require Additional Information</option>
                                    <option value="not_eligible">Not Eligible for KP Processing</option>
                                </select>
                            </div>

                            <div class="kp-field" id="natureField" style="display:none;">
                                <label>Nature of Case <span>*</span></label>
                                <select name="kp_nature">
                                    <?php foreach (kp_case_natures() as $natureKey => $natureLabel): ?>
                                    <option value="<?= $natureKey ?>" <?= kp_case_nature($case) === $natureKey ? 'selected' : '' ?>><?= $natureLabel ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="display:block;margin-top:4px;color:#64748b;">Suggested from the case type; used in the monthly KP Monitoring Form.</small>
                            </div>

                            <div id="coverageFields" style="display:none;">
                                <div class="kp-field">
                                    <label>Ground for exclusion <span>*</span></label>
                                    <select name="not_covered_reason" id="coverageReason">
                                        <option value="">— Select the legal ground —</option>
                                        <?php foreach (kp_not_covered_reasons() as $reasonKey => $reasonLabel): ?>
                                        <option value="<?= htmlspecialchars($reasonKey) ?>"><?= htmlspecialchars($reasonLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="kp-field">
                                    <label>Referred to (optional)</label>
                                    <input type="text" name="referral_destination" placeholder="e.g. PNP Women and Children Protection Desk, Office of the Prosecutor, DOLE">
                                </div>
                            </div>

                            <div class="kp-field" id="missingItemsField" style="display:none;">
                                <label>Required Items / Missing Information</label>
                                <textarea name="missing_items" rows="3" placeholder="List what information is needed from the complainant..."></textarea>
                            </div>

                            <div class="kp-field">
                                <label>Notes</label>
                                <textarea name="notes" rows="3" placeholder="Optional notes about this review decision..."></textarea>
                            </div>

                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-check"></i> Submit Decision</button>
                            </div>
                        </form>

                        <?php else: ?>
                        <div class="kp-info-box">
                            <i class="uil uil-check-circle"></i>
                            <div>
                                <strong>Review Complete</strong>
                                <p>This case has already been reviewed. Current status: <strong><?= htmlspecialchars(kp_status_label($kpStatus)) ?></strong></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Review History -->
                        <?php if (!empty($reviews)): ?>
                        <h4 class="kp-sub-title">Review History</h4>
                        <div class="kp-review-history">
                            <?php foreach ($reviews as $rev): ?>
                            <div class="kp-review-item">
                                <div class="kp-review-decision">
                                    <strong><?= htmlspecialchars(kp_status_label($rev['decision'])) ?></strong>
                                    <span class="kp-review-date"><?= date('M d, Y h:i A', strtotime($rev['created_at'])) ?></span>
                                </div>
                                <?php if ($rev['notes']): ?>
                                <div class="kp-review-notes"><?= nl2br(htmlspecialchars($rev['notes'])) ?></div>
                                <?php endif; ?>
                                <?php if ($rev['missing_items']): ?>
                                <div class="kp-review-missing"><strong>Required:</strong> <?= nl2br(htmlspecialchars($rev['missing_items'])) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Mediation ═══ -->
                <div class="kp-tab-content" id="tab-mediation">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Mediation</h3>

                        <?php if (in_array($kpStatus, ['accepted', 'mediation_scheduled', 'mediation_ongoing'])): ?>
                        <p class="kp-section-desc">Schedule and manage mediation hearings before the Punong Barangay. The summons (KP Form #9) and the notice to the complainant (KP Form #8) are issued on the next working day after the complaint is received.</p>
                        <form method="POST" action="kp_hearing.php" class="kp-hearing-form">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="stage" value="mediation">
                            <?php kp_render_schedule_fields($defaultLocation); ?>
                            <div class="kp-field">
                                <label>Purpose</label>
                                <input type="text" name="purpose" value="Mediation hearing between the parties">
                            </div>
                            <div class="kp-form-actions">
                                <button type="submit" name="action" value="schedule" class="kp-btn kp-btn-primary"><i class="uil uil-calendar-alt"></i> Schedule Hearing</button>
                            </div>
                        </form>
                        <?php elseif ($kpStatus === 'mediation_noshow_complainant'): ?>
                        <div class="kp-info-box" style="border-left: 3px solid #b45309;">
                            <i class="uil uil-exclamation-triangle" style="color:#b45309"></i>
                            <div><strong>Complainant failed to appear</strong><p>Schedule a hearing for the complainant to explain the absence (KP Form #18).</p></div>
                        </div>
                        <?php if (!kp_filter_hearings($hearings, 'mediation', 'explanation_complainant') || !array_filter(kp_filter_hearings($hearings, 'mediation', 'explanation_complainant'), fn($h) => empty($h['outcome']))): ?>
                        <form method="POST" action="kp_hearing.php" class="kp-hearing-form" style="margin-top:12px;">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <?php kp_render_schedule_fields($defaultLocation); ?>
                            <div class="kp-form-actions">
                                <button type="submit" name="action" value="schedule_explanation" class="kp-btn kp-btn-primary"><i class="uil uil-calendar-alt"></i> Schedule Explanation Hearing</button>
                            </div>
                        </form>
                        <?php endif; ?>
                        <?php elseif ($kpStatus === 'mediation_settled'): ?>
                        <div class="kp-info-box kp-info-success">
                            <i class="uil uil-check-circle"></i>
                            <div><strong>Mediation Settled</strong><p>Parties reached settlement during mediation. Record the settlement in the Settlement tab.</p></div>
                        </div>
                        <?php elseif (!in_array($kpStatus, kp_post_acceptance_statuses(), true)): ?>
                        <div class="kp-info-box">
                            <i class="uil uil-info-circle"></i>
                            <div><strong>Mediation not available</strong><p>The case must be accepted before scheduling mediation.</p></div>
                        </div>
                        <?php endif; ?>

                        <?php $medHearings = kp_filter_hearings($hearings, 'mediation'); ?>
                        <?php if ($medHearings): ?>
                        <h4 class="kp-sub-title">Mediation Hearings</h4>
                        <?php kp_render_hearings($medHearings, (int)$case['id'], 'mediation', [
                            'adjourned'          => 'Adjourned',
                            'settled'            => 'Settled',
                            'failed'             => 'Failed — proceed to Pangkat',
                            'complainant_absent' => 'Complainant absent',
                            'respondent_absent'  => 'Respondent absent — proceed to Pangkat',
                        ], in_array($kpStatus, ['mediation_scheduled', 'mediation_ongoing'], true)); ?>

                        <?php if (!in_array($kpStatus, kp_terminal_statuses(), true)): ?>
                        <h4 class="kp-sub-title">Service of Summons (Officer's Return)</h4>
                        <form method="POST" action="kp_hearing.php">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="record_service">
                            <div class="kp-field-row">
                                <div class="kp-field">
                                    <label>Hearing <span>*</span></label>
                                    <select name="hearing_id" required>
                                        <?php foreach (array_reverse($medHearings) as $mh): ?>
                                        <option value="<?= (int)$mh['id'] ?>"><?= date('M d, Y', strtotime($mh['hearing_date'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="kp-field">
                                    <label>Date Served <span>*</span></label>
                                    <input type="date" name="served_date" required value="<?= $today ?>" max="<?= $today ?>">
                                </div>
                            </div>
                            <div class="kp-field-row">
                                <div class="kp-field">
                                    <label>Mode of Service <span>*</span></label>
                                    <select name="service_method" required>
                                        <option value="personal">Handed to respondent in person</option>
                                        <option value="refused">Handed in person, but refused to receive</option>
                                        <option value="left_at_dwelling">Left at dwelling with a person of suitable age</option>
                                        <option value="left_at_office">Left at office/place of business with person in charge</option>
                                    </select>
                                </div>
                                <div class="kp-field">
                                    <label>Received by (if left with another person)</label>
                                    <input type="text" name="received_by" placeholder="Name of person who received it">
                                </div>
                            </div>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-save"></i> Record Service</button>
                            </div>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php $medExplain = kp_filter_hearings($hearings, 'mediation', 'explanation_complainant'); ?>
                        <?php if ($medExplain): ?>
                        <h4 class="kp-sub-title">Failure-to-Appear Hearings</h4>
                        <?php kp_render_hearings($medExplain, (int)$case['id'], 'mediation', [
                            'justified'   => 'Absence justified — resume mediation',
                            'unjustified' => 'Absence unjustified — dismiss and bar action',
                        ], $kpStatus === 'mediation_noshow_complainant'); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Pangkat ═══ -->
                <div class="kp-tab-content" id="tab-pangkat">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Pangkat ng Tagapagkasundo</h3>

                        <?php if ($kpStatus === 'pangkat_constitution' && !$hasPangkat): ?>
                        <p class="kp-section-desc">Within 15 days from the last mediation hearing, the Punong Barangay calls the parties to choose the three Pangkat members from the Lupon (KP Form #10). If they fail to agree or to appear, the Punong Barangay determines the members by drawing of lots. Once constituted, the Pangkat meets within 3 days.</p>

                        <h4 class="kp-sub-title">1. Constitution Meeting</h4>
                        <form method="POST" action="kp_hearing.php" class="kp-hearing-form">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="stage" value="conciliation">
                            <input type="hidden" name="purpose_type" value="pangkat_constitution">
                            <?php kp_render_schedule_fields($defaultLocation); ?>
                            <div class="kp-form-actions">
                                <button type="submit" name="action" value="schedule" class="kp-btn kp-btn-primary"><i class="uil uil-calendar-alt"></i> Schedule Constitution Meeting</button>
                            </div>
                        </form>
                        <?php kp_render_hearings(kp_filter_hearings($hearings, 'conciliation', 'pangkat_constitution'), (int)$case['id'], 'conciliation', ['held' => 'Meeting held'], true); ?>

                        <h4 class="kp-sub-title">2. Chosen Members and Officers</h4>
                        <form method="POST" action="kp_pangkat.php" class="kp-pangkat-form">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <?php for ($i = 1; $i <= 3; $i++): ?>
                            <div class="kp-field-row">
                                <div class="kp-field">
                                    <label>Member <?= $i ?> <span>*</span></label>
                                    <input type="text" name="member_<?= $i ?>_name" required placeholder="Full name of Lupon member">
                                </div>
                                <div class="kp-field">
                                    <label>How chosen <span>*</span></label>
                                    <select name="member_<?= $i ?>_chosen_by" required>
                                        <option value="both_parties">Agreed by both parties</option>
                                        <option value="complainant">Chosen by complainant</option>
                                        <option value="respondent">Chosen by respondent</option>
                                        <option value="drawing_lots">By drawing of lots</option>
                                    </select>
                                </div>
                            </div>
                            <?php endfor; ?>
                            <div class="kp-field-row">
                                <div class="kp-field">
                                    <label>Elected Chairman <span>*</span></label>
                                    <select name="chairman" required>
                                        <option value="">— Select —</option>
                                        <option value="1">Member 1</option><option value="2">Member 2</option><option value="3">Member 3</option>
                                    </select>
                                </div>
                                <div class="kp-field">
                                    <label>Elected Secretary <span>*</span></label>
                                    <select name="secretary" required>
                                        <option value="">— Select —</option>
                                        <option value="1">Member 1</option><option value="2">Member 2</option><option value="3">Member 3</option>
                                    </select>
                                </div>
                            </div>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-users-alt"></i> Record Pangkat</button>
                            </div>
                        </form>

                        <?php elseif ($hasPangkat): ?>
                        <div class="kp-info-box kp-info-success">
                            <i class="uil uil-check-circle"></i>
                            <div><strong>Pangkat Constituted</strong><p>Generate KP Form #11 for each chosen member from the Documents tab.</p></div>
                        </div>
                        <div class="kp-pangkat-list">
                            <?php foreach ($pangkat as $pm): ?>
                            <?php $chosenLabels = ['both_parties' => 'Agreed by both parties', 'complainant' => 'Chosen by complainant', 'respondent' => 'Chosen by respondent', 'drawing_lots' => 'By drawing of lots']; ?>
                            <div class="kp-pangkat-member">
                                <div class="kp-pm-avatar"><i class="uil uil-user"></i></div>
                                <div>
                                    <strong><?= htmlspecialchars($pm['member_name']) ?></strong>
                                    <span class="kp-pm-role"><?= ucfirst($pm['role']) ?> — <?= htmlspecialchars($chosenLabels[$pm['chosen_by']] ?? ucfirst(str_replace('_', ' ', $pm['chosen_by']))) ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php kp_render_hearings(kp_filter_hearings($hearings, 'conciliation', 'pangkat_constitution'), (int)$case['id'], 'conciliation', ['held' => 'Meeting held'], $kpStatus === 'pangkat_constitution'); ?>
                        <?php else: ?>
                        <div class="kp-info-box">
                            <i class="uil uil-info-circle"></i>
                            <div><strong>Not yet available</strong><p>Pangkat constitution begins after mediation fails.</p></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Conciliation ═══ -->
                <div class="kp-tab-content" id="tab-conciliation">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Conciliation</h3>
                        <?php if ($hasPangkat && in_array($kpStatus, ['pangkat_constitution', 'conciliation_scheduled', 'conciliation_ongoing'], true)): ?>
                        <p class="kp-section-desc">Schedule conciliation hearings before the Pangkat ng Tagapagkasundo (KP Form #12). The Pangkat meets within 3 days of its constitution and has 15 days to settle the dispute<?= $case['conciliation_deadline'] ? ' — until <strong>' . date('M d, Y', strtotime($case['conciliation_deadline'])) . '</strong>' : '' ?>.</p>
                        <form method="POST" action="kp_hearing.php" class="kp-hearing-form">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="stage" value="conciliation">
                            <?php kp_render_schedule_fields($defaultLocation); ?>
                            <div class="kp-form-actions">
                                <button type="submit" name="action" value="schedule" class="kp-btn kp-btn-primary"><i class="uil uil-calendar-alt"></i> Schedule Hearing</button>
                            </div>
                        </form>

                        <?php if (in_array($kpStatus, ['conciliation_scheduled', 'conciliation_ongoing'], true) && (int)($case['conciliation_extended'] ?? 0) === 0 && !empty($case['conciliation_deadline'])): ?>
                        <h4 class="kp-sub-title">Extend Conciliation Period</h4>
                        <form method="POST" action="kp_hearing.php">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <div class="kp-field">
                                <label>Reason (meritorious case) <span>*</span></label>
                                <textarea name="reason" rows="2" required placeholder="Why the Pangkat needs 15 more days..."></textarea>
                            </div>
                            <div class="kp-form-actions">
                                <button type="submit" name="action" value="extend_conciliation" class="kp-btn kp-btn-primary"><i class="uil uil-calendar-alt"></i> Extend by 15 Days</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php elseif (in_array($kpStatus, ['conciliation_noshow_complainant', 'conciliation_noshow_respondent'], true)): ?>
                        <?php
                            $absentParty = $kpStatus === 'conciliation_noshow_respondent' ? 'respondent' : 'complainant';
                            $explainType = 'explanation_' . $absentParty;
                            $pendingExplain = array_filter(kp_filter_hearings($hearings, 'conciliation', $explainType), fn($h) => empty($h['outcome']));
                        ?>
                        <div class="kp-info-box" style="border-left: 3px solid #b45309;">
                            <i class="uil uil-exclamation-triangle" style="color:#b45309"></i>
                            <div><strong><?= ucfirst($absentParty) ?> failed to appear before the Pangkat</strong><p>Schedule a hearing for the <?= $absentParty ?> to explain the absence (KP Form #<?= $absentParty === 'complainant' ? '18' : '19' ?>).</p></div>
                        </div>
                        <?php if (!$pendingExplain): ?>
                        <form method="POST" action="kp_hearing.php" class="kp-hearing-form" style="margin-top:12px;">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <?php kp_render_schedule_fields($defaultLocation); ?>
                            <div class="kp-form-actions">
                                <button type="submit" name="action" value="schedule_explanation" class="kp-btn kp-btn-primary"><i class="uil uil-calendar-alt"></i> Schedule Explanation Hearing</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php elseif (!$hasPangkat): ?>
                        <div class="kp-info-box">
                            <i class="uil uil-info-circle"></i>
                            <div><strong>Conciliation not yet available</strong><p>Conciliation begins after a Pangkat is constituted following failed mediation.</p></div>
                        </div>
                        <?php endif; ?>

                        <?php $conHearings = kp_filter_hearings($hearings, 'conciliation'); ?>
                        <?php if ($conHearings): ?>
                        <h4 class="kp-sub-title">Conciliation Hearings</h4>
                        <?php kp_render_hearings($conHearings, (int)$case['id'], 'conciliation', [
                            'adjourned'          => 'Adjourned',
                            'settled'            => 'Settled',
                            'failed'             => 'Failed — issue Certification to File Action',
                            'complainant_absent' => 'Complainant absent',
                            'respondent_absent'  => 'Respondent absent',
                        ], in_array($kpStatus, ['conciliation_scheduled', 'conciliation_ongoing'], true)); ?>
                        <?php endif; ?>

                        <?php $conExplainC = kp_filter_hearings($hearings, 'conciliation', 'explanation_complainant'); ?>
                        <?php $conExplainR = kp_filter_hearings($hearings, 'conciliation', 'explanation_respondent'); ?>
                        <?php if ($conExplainC || $conExplainR): ?>
                        <h4 class="kp-sub-title">Failure-to-Appear Hearings</h4>
                        <?php kp_render_hearings($conExplainC, (int)$case['id'], 'conciliation', [
                            'justified'   => 'Absence justified — resume conciliation',
                            'unjustified' => 'Absence unjustified — dismiss and bar action',
                        ], $kpStatus === 'conciliation_noshow_complainant'); ?>
                        <?php kp_render_hearings($conExplainR, (int)$case['id'], 'conciliation', [
                            'justified'   => 'Absence justified — resume conciliation',
                            'unjustified' => 'Absence unjustified — certify to file action, bar counterclaim',
                        ], $kpStatus === 'conciliation_noshow_respondent'); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Settlement ═══ -->
                <div class="kp-tab-content" id="tab-settlement">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Settlement &amp; Finality</h3>

                        <?php if (in_array($kpStatus, ['mediation_settled', 'conciliation_settled'], true)): ?>
                        <p class="kp-section-desc">Record the terms of the amicable settlement (KP Form #16), written in a language or dialect known to the parties. The 10-day repudiation period starts on the settlement date.</p>
                        <form method="POST" action="kp_settlement.php" class="kp-settlement-form">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="record">
                            <div class="kp-field">
                                <label>Settlement Terms <span>*</span></label>
                                <textarea name="terms" rows="5" required placeholder="Describe the agreed terms of settlement in detail..."></textarea>
                            </div>
                            <div class="kp-field">
                                <label>Settlement Date <span>*</span></label>
                                <input type="date" name="settlement_date" required value="<?= $today ?>" max="<?= $today ?>">
                            </div>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-file-check-alt"></i> Record Settlement</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($kpStatus === 'settlement_repudiation' && $activeSettlement && $today <= $activeSettlement['repudiation_deadline']): ?>
                        <h4 class="kp-sub-title">Record Repudiation (KP Form #17)</h4>
                        <p class="kp-section-desc">A party may repudiate the settlement until <strong><?= date('M d, Y', strtotime($activeSettlement['repudiation_deadline'])) ?></strong> by a statement sworn before the Punong Barangay / Pangkat Chairman, only on the ground that consent was obtained by fraud, violence or intimidation.</p>
                        <form method="POST" action="kp_settlement.php">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="repudiate">
                            <div class="kp-field-row">
                                <div class="kp-field"><label>Repudiating Party <span>*</span></label><select name="party_role" required><?= $partyRoleOptions() ?></select></div>
                                <div class="kp-field"><label>Ground <span>*</span></label>
                                    <select name="ground" required><option value="">— Select —</option><option value="fraud">Fraud</option><option value="violence">Violence</option><option value="intimidation">Intimidation</option></select>
                                </div>
                            </div>
                            <div class="kp-field"><label>Details</label><textarea name="details" rows="3" placeholder="State the details of the ground..."></textarea></div>
                            <div class="kp-field"><label>Date Sworn <span>*</span></label><input type="date" name="sworn_date" required value="<?= $today ?>" min="<?= htmlspecialchars($activeSettlement['settlement_date']) ?>" max="<?= $today ?>"></div>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-save"></i> Record Repudiation</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if (in_array($kpStatus, ['settlement_repudiation', 'arbitration_awarded'], true) && $activeSettlement): ?>
                        <h4 class="kp-sub-title">Finality</h4>
                        <?php if ($today > $activeSettlement['repudiation_deadline']): ?>
                        <form method="POST" action="kp_settlement.php">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="confirm_final">
                            <p class="kp-section-desc">The period ended on <?= date('M d, Y', strtotime($activeSettlement['repudiation_deadline'])) ?> with no repudiation recorded<?= $activeSettlement['settlement_type'] === 'arbitration_award' ? ' (confirm also that no petition to nullify the award was filed in court)' : '' ?>.</p>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-check-circle"></i> Confirm Final &amp; Executory</button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="kp-info-box"><i class="uil uil-clock"></i><div><strong>Finality on <?= date('M d, Y', strtotime($activeSettlement['repudiation_deadline'] . ' +1 day')) ?></strong><p>The settlement/award becomes final and executory once the 10-day period ends.</p></div></div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!empty($settlements)): ?>
                        <h4 class="kp-sub-title">Settlement Records</h4>
                        <?php foreach ($settlements as $stl): ?>
                        <div class="kp-settlement-card">
                            <div class="kp-stl-header">
                                <strong><?= $stl['settlement_type'] === 'amicable' ? 'Amicable Settlement' : 'Arbitration Award' ?><?= !empty($stl['stage']) ? ' — ' . htmlspecialchars(ucfirst($stl['stage'])) : '' ?></strong>
                                <span class="kp-stl-date"><?= date('M d, Y', strtotime($stl['settlement_date'])) ?></span>
                            </div>
                            <div class="kp-stl-terms"><?= nl2br(htmlspecialchars($stl['terms'])) ?></div>
                            <div class="kp-stl-footer">
                                <span><?= $stl['settlement_type'] === 'amicable' ? 'Repudiation' : 'Nullification' ?> period ends: <strong><?= date('M d, Y', strtotime($stl['repudiation_deadline'])) ?></strong></span>
                                <?php if ($stl['repudiated']): ?>
                                <span class="kp-badge-red">REPUDIATED</span>
                                <?php elseif ($stl['final_at']): ?>
                                <span class="kp-badge-green">FINAL &amp; EXECUTORY</span>
                                <?php else: ?>
                                <span class="kp-badge-yellow"><?= $stl['settlement_type'] === 'amicable' ? 'REPUDIATION PERIOD' : 'NULLIFICATION PERIOD' ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$stl['repudiated']): ?>
                            <?php
                                // LGC Sec. 419: transmit within 5 days of the award, or of the end of the settlement's repudiation period.
                                $isAwardRow   = $stl['settlement_type'] === 'arbitration_award';
                                $transmitFrom = $isAwardRow ? $stl['settlement_date'] : date('Y-m-d', strtotime($stl['repudiation_deadline'] . ' +1 day'));
                                $transmitDue  = date('Y-m-d', strtotime(($isAwardRow ? $stl['settlement_date'] : $stl['repudiation_deadline']) . ' +5 days'));
                            ?>
                            <div class="kp-stl-footer" style="border-top:1px dashed #e2e8f0;margin-top:8px;padding-top:8px;">
                                <?php if (!empty($stl['transmitted_at'])): ?>
                                <span>Transmitted to the city court on <strong><?= date('M d, Y', strtotime($stl['transmitted_at'])) ?></strong></span>
                                <?php elseif ($today >= $transmitFrom): ?>
                                <form method="POST" action="kp_settlement.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                    <input type="hidden" name="action" value="record_transmittal">
                                    <input type="hidden" name="settlement_id" value="<?= (int)$stl['id'] ?>">
                                    <span>Send a copy to the city court by <strong><?= date('M d, Y', strtotime($transmitDue)) ?></strong>. Sent on:</span>
                                    <input type="date" name="transmitted_on" required value="<?= $today ?>" min="<?= $transmitFrom ?>" max="<?= $today ?>">
                                    <button type="submit" class="kp-btn kp-btn-sm"><i class="uil uil-save"></i> Record Transmittal</button>
                                </form>
                                <?php else: ?>
                                <span>Send a copy to the city court between <strong><?= date('M d, Y', strtotime($transmitFrom)) ?></strong> and <strong><?= date('M d, Y', strtotime($transmitDue)) ?></strong>.</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php elseif (!in_array($kpStatus, ['mediation_settled', 'conciliation_settled'], true)): ?>
                        <div class="kp-info-box">
                            <i class="uil uil-info-circle"></i>
                            <div><strong>No settlements yet</strong><p>Settlements are recorded after parties agree during mediation or conciliation.</p></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Arbitration ═══ -->
                <div class="kp-tab-content" id="tab-arbitration">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Arbitration</h3>

                        <?php if (in_array($kpStatus, ['mediation_scheduled', 'mediation_ongoing', 'pangkat_constitution', 'conciliation_scheduled', 'conciliation_ongoing'], true)): ?>
                        <?php $arbitrationDuringMediation = in_array($kpStatus, ['mediation_scheduled', 'mediation_ongoing'], true); ?>
                        <p class="kp-section-desc">At any stage, the parties may agree in writing to abide by an arbitration award (KP Form #14). The Punong Barangay arbitrates when they agree during mediation; the Pangkat arbitrates when they agree during conciliation. Either party may repudiate the agreement within 5 days.</p>
                        <?php if (!$arbitrationDuringMediation && !$hasPangkat): ?>
                        <div class="kp-info-box"><i class="uil uil-info-circle"></i><div><strong>Constitute the Pangkat first</strong><p>During conciliation, the Pangkat ng Tagapagkasundo serves as the arbitrator.</p></div></div>
                        <?php else: ?>
                        <form method="POST" action="kp_arbitration.php">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="agree">
                            <div class="kp-field-row">
                                <div class="kp-field">
                                    <label>Arbitrator</label>
                                    <input type="text" value="<?= $arbitrationDuringMediation ? 'Punong Barangay (Lupon Chairman)' : 'Pangkat ng Tagapagkasundo' ?>" readonly>
                                </div>
                                <div class="kp-field">
                                    <label>Date of Agreement <span>*</span></label>
                                    <input type="date" name="agreement_date" required value="<?= $today ?>" max="<?= $today ?>">
                                </div>
                            </div>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-user-check"></i> Record Agreement for Arbitration</button>
                            </div>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($latestAgreement): ?>
                        <div class="kp-settlement-card">
                            <div class="kp-stl-header">
                                <strong>Agreement for Arbitration — <?= $latestAgreement['arbitrator'] === 'pangkat' ? 'Pangkat ng Tagapagkasundo' : 'Punong Barangay' ?></strong>
                                <span class="kp-stl-date"><?= date('M d, Y', strtotime($latestAgreement['agreement_date'])) ?></span>
                            </div>
                            <div class="kp-stl-footer">
                                <span>Repudiation period ends: <strong><?= date('M d, Y', strtotime($latestAgreement['repudiation_deadline'])) ?></strong></span>
                                <?php if ((int)$latestAgreement['repudiated'] === 1): ?><span class="kp-badge-red">REPUDIATED</span>
                                <?php elseif ($agreementWindowOpen): ?><span class="kp-badge-yellow">REPUDIATION PERIOD</span>
                                <?php else: ?><span class="kp-badge-green">BINDING</span><?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($kpStatus === 'arbitration_agreed' && $agreementWindowOpen): ?>
                        <h4 class="kp-sub-title">Record Repudiation of the Agreement (KP Form #17)</h4>
                        <form method="POST" action="kp_arbitration.php">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="repudiate_agreement">
                            <div class="kp-field-row">
                                <div class="kp-field"><label>Repudiating Party <span>*</span></label><select name="party_role" required><?= $partyRoleOptions() ?></select></div>
                                <div class="kp-field"><label>Ground <span>*</span></label>
                                    <select name="ground" required><option value="">— Select —</option><option value="fraud">Fraud</option><option value="violence">Violence</option><option value="intimidation">Intimidation</option></select>
                                </div>
                            </div>
                            <div class="kp-field"><label>Details</label><textarea name="details" rows="3"></textarea></div>
                            <div class="kp-field"><label>Date Sworn <span>*</span></label><input type="date" name="sworn_date" required value="<?= $today ?>" min="<?= htmlspecialchars($latestAgreement['agreement_date']) ?>" max="<?= $today ?>"></div>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-save"></i> Record Repudiation</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if (in_array($kpStatus, ['arbitration_agreed', 'arbitration_hearing'], true) && $latestAgreement && !$agreementWindowOpen): ?>
                        <h4 class="kp-sub-title">Schedule Arbitration Hearing</h4>
                        <form method="POST" action="kp_hearing.php" class="kp-hearing-form">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="stage" value="arbitration">
                            <?php kp_render_schedule_fields($defaultLocation, max($today, date('Y-m-d', strtotime($latestAgreement['repudiation_deadline'] . ' +1 day')))); ?>
                            <div class="kp-form-actions">
                                <button type="submit" name="action" value="schedule" class="kp-btn kp-btn-primary"><i class="uil uil-calendar-alt"></i> Schedule Arbitration Hearing</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php $arbHearings = kp_filter_hearings($hearings, 'arbitration'); ?>
                        <?php if ($arbHearings): ?>
                        <h4 class="kp-sub-title">Arbitration Hearings</h4>
                        <?php kp_render_hearings($arbHearings, (int)$case['id'], 'arbitration', [
                            'adjourned' => 'Adjourned',
                            'concluded' => 'Submitted for award',
                        ], $kpStatus === 'arbitration_hearing'); ?>
                        <?php endif; ?>

                        <?php if ($kpStatus === 'arbitration_hearing' && $lastAwardHearing): ?>
                        <?php
                            $awardEarliest = max($lastAwardHearing, date('Y-m-d', strtotime($latestAgreement['repudiation_deadline'] . ' +1 day')));
                            $awardDue = date('Y-m-d', strtotime($latestAgreement['repudiation_deadline'] . ' +10 days'));
                        ?>
                        <h4 class="kp-sub-title">Arbitration Award (KP Form #15)</h4>
                        <p class="kp-section-desc">The award may be made from <strong><?= date('M d, Y', strtotime($awardEarliest)) ?></strong> and should be made by <strong><?= date('M d, Y', strtotime($awardDue)) ?></strong> (within 10 days after the agreement's repudiation period).</p>
                        <?php if ($today >= $awardEarliest): ?>
                        <form method="POST" action="kp_arbitration.php">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="award">
                            <div class="kp-field"><label>Award <span>*</span></label><textarea name="award_text" rows="5" required placeholder="State the award..."></textarea></div>
                            <div class="kp-field"><label>Date of Award <span>*</span></label><input type="date" name="award_date" required value="<?= $today ?>" min="<?= $awardEarliest ?>" max="<?= $today ?>"></div>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-file-check-alt"></i> Record Award</button>
                            </div>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!$latestAgreement && !in_array($kpStatus, ['mediation_scheduled', 'mediation_ongoing', 'pangkat_constitution', 'conciliation_scheduled', 'conciliation_ongoing'], true)): ?>
                        <div class="kp-info-box">
                            <i class="uil uil-info-circle"></i>
                            <div><strong>Arbitration not available</strong><p>The parties may agree in writing to arbitration during mediation or conciliation.</p></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Compliance ═══ -->
                <div class="kp-tab-content" id="tab-compliance">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Compliance Monitoring</h3>

                        <?php if ($kpStatus === 'settlement_final'): ?>
                        <p class="kp-section-desc">The settlement/award is final and executory. Monitor whether the parties comply with its terms.</p>
                        <form method="POST" action="kp_compliance.php">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <input type="hidden" name="action" value="start_monitoring">
                            <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-check-circle"></i> Begin Compliance Monitoring</button>
                        </form>
                        <?php elseif ($kpStatus === 'compliance_monitoring'): ?>
                        <div class="kp-info-box kp-info-success">
                            <i class="uil uil-eye"></i>
                            <div><strong>Compliance Monitoring Active</strong><p>The Lupon may execute the settlement/award until <?= $executionLimit ? date('M d, Y', strtotime($executionLimit)) : '—' ?> (6 months from the settlement date).</p></div>
                        </div>
                        <form method="POST" action="kp_compliance.php" class="kp-compliance-form" style="margin-top:16px;">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <div class="kp-field">
                                <label>Compliance Status Update</label>
                                <select name="action" required>
                                    <option value="">— Select Action —</option>
                                    <option value="mark_complied">Fully complied — close the case</option>
                                    <option value="partial_compliance">Partial compliance — add note</option>
                                    <option value="non_compliance">Non-compliance — note and go to Execution</option>
                                </select>
                            </div>
                            <div class="kp-field">
                                <label>Notes</label>
                                <textarea name="notes" rows="3" placeholder="Describe the compliance status..."></textarea>
                            </div>
                            <div class="kp-form-actions">
                                <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-save"></i> Update Compliance</button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="kp-info-box">
                            <i class="uil uil-info-circle"></i>
                            <div><strong>Compliance monitoring not yet available</strong><p>A settlement or award must become final and executory first.</p></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Execution ═══ -->
                <div class="kp-tab-content" id="tab-execution">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Execution</h3>

                        <?php if ($kpStatus === 'compliance_monitoring' && $finalSettlement): ?>
                            <?php if ($today <= $executionLimit): ?>
                            <p class="kp-section-desc">If the obliged party fails to comply, the other party may file a Motion for Execution (KP Form #23) with the Punong Barangay until <strong><?= date('M d, Y', strtotime($executionLimit)) ?></strong>.</p>
                            <form method="POST" action="kp_execution.php">
                                <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                <input type="hidden" name="action" value="file_motion">
                                <div class="kp-field-row">
                                    <div class="kp-field"><label>Moving Party <span>*</span></label><select name="moving_party_role" required><?= $partyRoleOptions() ?></select></div>
                                    <div class="kp-field"><label>Date Filed <span>*</span></label><input type="date" name="motion_date" required value="<?= $today ?>" max="<?= min($today, $executionLimit) ?>"></div>
                                </div>
                                <div class="kp-field"><label>Notes</label><textarea name="notes" rows="2" placeholder="Terms not complied with..."></textarea></div>
                                <div class="kp-form-actions">
                                    <button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-bolt"></i> File Motion for Execution</button>
                                </div>
                            </form>
                            <?php else: ?>
                            <div class="kp-info-box" style="border-left: 3px solid #dc2626;">
                                <i class="uil uil-exclamation-triangle" style="color:#dc2626"></i>
                                <div><strong>6-month execution period has lapsed</strong><p>The settlement/award may now be enforced only by motion in the Municipal Trial Court.</p></div>
                            </div>
                            <form method="POST" action="kp_execution.php" style="margin-top:12px;">
                                <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                <input type="hidden" name="action" value="close_lapsed">
                                <div class="kp-field"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
                                <div class="kp-form-actions"><button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-archive"></i> Close Case</button></div>
                            </form>
                            <?php endif; ?>

                        <?php elseif ($kpStatus === 'execution_pending' && $latestExecution): ?>
                            <?php if (empty($latestExecution['hearing_date'])): ?>
                            <h4 class="kp-sub-title">Hearing on the Motion (KP Form #24)</h4>
                            <form method="POST" action="kp_execution.php" class="kp-hearing-form">
                                <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                <input type="hidden" name="action" value="schedule_hearing">
                                <?php kp_render_schedule_fields($defaultLocation, $latestExecution['motion_date'], date('Y-m-d', strtotime($latestExecution['motion_date'] . ' +5 days'))); ?>
                                <div class="kp-form-actions"><button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-calendar-alt"></i> Schedule Hearing</button></div>
                            </form>
                            <?php else: ?>
                            <div class="kp-info-box">
                                <i class="uil uil-clock"></i>
                                <div><strong>Voluntary compliance period ends <?= date('M d, Y', strtotime($latestExecution['compliance_deadline'])) ?></strong><p>Hearing on the motion: <?= date('M d, Y', strtotime($latestExecution['hearing_date'])) ?>.</p></div>
                            </div>
                            <?php endif; ?>

                            <form method="POST" action="kp_execution.php" style="margin-top:16px;">
                                <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                <div class="kp-field">
                                    <label>Execution Action</label>
                                    <select name="action" required>
                                        <option value="">— Select —</option>
                                        <option value="complied">Obliged party complied voluntarily</option>
                                        <?php if (!empty($latestExecution['compliance_deadline']) && empty($latestExecution['execution_issued_at']) && $today > $latestExecution['compliance_deadline']): ?>
                                        <option value="issue_notice">Issue Notice of Execution (KP Form #25)</option>
                                        <?php endif; ?>
                                        <?php if (!empty($latestExecution['execution_issued_at'])): ?>
                                        <option value="mark_executed">Execution carried out</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="kp-field"><label>Amount to be realized (for KP Form #25, if any)</label><input type="text" name="amount" placeholder="e.g. 5,000.00"></div>
                                <div class="kp-field"><label>Notes</label><textarea name="notes" rows="3"></textarea></div>
                                <div class="kp-form-actions"><button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-save"></i> Update Execution</button></div>
                            </form>

                        <?php elseif ($kpStatus === 'execution_completed'): ?>
                            <div class="kp-info-box kp-info-success">
                                <i class="uil uil-check-circle"></i>
                                <div><strong>Execution Completed</strong><p>Close the case to record its disposition.</p></div>
                            </div>
                            <form method="POST" action="kp_execution.php" style="margin-top:12px;">
                                <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                <input type="hidden" name="action" value="close_case">
                                <div class="kp-field"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
                                <div class="kp-form-actions"><button type="submit" class="kp-btn kp-btn-primary"><i class="uil uil-archive"></i> Close Case</button></div>
                            </form>

                        <?php else: ?>
                        <div class="kp-info-box">
                            <i class="uil uil-info-circle"></i>
                            <div><strong>Execution not yet available</strong><p>Execution begins when a party fails to comply with a final settlement or award.</p></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($executions)): ?>
                        <h4 class="kp-sub-title">Execution History</h4>
                        <?php foreach ($executions as $exec): ?>
                        <div class="kp-settlement-card">
                            <div class="kp-stl-header">
                                <strong>Motion for Execution</strong>
                                <span class="kp-stl-date"><?= date('M d, Y', strtotime($exec['motion_date'] ?? $exec['created_at'])) ?></span>
                            </div>
                            <?php if (!empty($exec['notes'])): ?>
                            <div class="kp-stl-terms"><?= nl2br(htmlspecialchars($exec['notes'])) ?></div>
                            <?php endif; ?>
                            <div class="kp-stl-footer">
                                <span>Status: <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)($exec['status'] ?? 'pending')))) ?></strong></span>
                                <?php if (!empty($exec['amount'])): ?><span>Amount: PHP <?= number_format((float)$exec['amount'], 2) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Disposition ═══ -->
                <div class="kp-tab-content" id="tab-disposition">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Case Disposition</h3>
                        <?php if (in_array($kpStatus, kp_terminal_statuses(), true)): ?>
                        <?php
                            $dispKey  = (string)($case['kp_disposition'] ?? '');
                            $dispInfo = kp_dispositions()[$dispKey] ?? null;
                        ?>
                        <div class="kp-info-box <?= in_array($kpStatus, ['closed', 'certified_to_court'], true) ? 'kp-info-success' : '' ?>">
                            <i class="uil uil-<?= $kpStatus === 'closed' ? 'check-circle' : ($kpStatus === 'certified_to_court' ? 'file-check-alt' : 'ban') ?>"></i>
                            <div>
                                <strong>Case <?= htmlspecialchars(kp_status_label($kpStatus)) ?></strong>
                                <p><?= $dispInfo ? htmlspecialchars($dispInfo['label']) : 'This case has been finalized.' ?></p>
                                <?php if ($referral): ?>
                                <p><strong>Ground:</strong> <?= htmlspecialchars(kp_not_covered_reasons()[$referral['reason']] ?? $referral['reason']) ?></p>
                                <?php if (!empty($referral['destination'])): ?>
                                <p><strong>Referred to:</strong> <?= htmlspecialchars($referral['destination']) ?></p>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($dispInfo && $dispInfo['form']): ?>
                                <p>Issue <strong>KP Form #<?= htmlspecialchars(kp_form_label((int)$dispInfo['form'])) ?></strong><?= $dispKey === 'cfa_respondent_absent' ? ' and <strong>KP Form #' . kp_form_label(24) . '</strong>' : '' ?> from the Documents tab.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="kp-info-box">
                            <i class="uil uil-info-circle"></i>
                            <div><strong>Case still in progress</strong><p>The disposition is recorded automatically by the step that concludes the case: failed conciliation, an unjustified absence, a repudiation, full compliance, or execution.</p></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Documents ═══ -->
                <div class="kp-tab-content <?= $requestedTab === 'documents' ? 'active' : '' ?>" id="tab-documents">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">KP Documents</h3>
                        <p class="kp-section-desc">Generate, download, and manage KP forms for this case. Only forms that apply to the proceedings recorded so far are offered.</p>

                        <!-- Generate Buttons -->
                        <div class="kp-doc-generate">
                            <h4 class="kp-sub-title">Generate Forms</h4>
                            <div class="kp-doc-btn-grid">
                                <?php
                                $availableForms = empty($case['archived_at']) ? kp_available_forms($case, $caseContext) : [];
                                foreach ($availableForms as $fn):
                                    $fi = kp_form_info($fn);
                                    if (!$fi) continue;
                                    $formLabel = kp_form_label($fn);
                                    if ($fn === 11):
                                        foreach ($pangkat as $pm):
                                ?>
                                <form method="POST" action="kp_document.php" style="display:inline;">
                                    <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                    <input type="hidden" name="form_number" value="11">
                                    <input type="hidden" name="pangkat_member_id" value="<?= (int)$pm['id'] ?>">
                                    <button type="submit" name="action" value="generate" class="kp-doc-btn">
                                        <i class="uil uil-file-alt"></i>
                                        <span>Form #11</span>
                                        <small><?= htmlspecialchars($fi['title']) ?> — <?= htmlspecialchars($pm['member_name']) ?></small>
                                    </button>
                                </form>
                                <?php
                                        endforeach;
                                        continue;
                                    elseif ($fn === 13):
                                ?>
                                <form method="POST" action="kp_document.php" class="kp-doc-btn" style="display:inline-flex;flex-direction:column;gap:6px;align-items:stretch;cursor:default;">
                                    <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                    <input type="hidden" name="form_number" value="13">
                                    <span><i class="uil uil-file-alt"></i> Form #13 — <?= htmlspecialchars($fi['title']) ?></span>
                                    <input type="text" name="witness_name" required placeholder="Witness name" style="font-size:12px;padding:4px 6px;">
                                    <input type="text" name="witness_address" placeholder="Witness address" style="font-size:12px;padding:4px 6px;">
                                    <button type="submit" name="action" value="generate" class="kp-btn kp-btn-sm">Generate Subpoena</button>
                                </form>
                                <?php
                                        continue;
                                    endif;
                                ?>
                                <form method="POST" action="kp_document.php" style="display:inline;">
                                    <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                                    <input type="hidden" name="form_number" value="<?= $fn ?>">
                                    <button type="submit" name="action" value="generate" class="kp-doc-btn">
                                        <i class="uil uil-file-alt"></i>
                                        <span>Form #<?= $formLabel ?></span>
                                        <small><?= htmlspecialchars($fi['title']) ?></small>
                                    </button>
                                </form>
                                <?php endforeach; ?>

                                <?php if (empty($availableForms)): ?>
                                <div class="kp-info-box">
                                    <i class="uil uil-info-circle"></i>
                                    <div><strong>No forms available yet</strong><p>Accept the case in the Review tab to begin generating KP forms.</p></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Existing Documents -->
                        <?php if (!empty($documents)): ?>
                        <h4 class="kp-sub-title">Generated Documents</h4>
                        <div class="kp-doc-list">
                            <?php foreach ($documents as $doc): ?>
                            <div class="kp-doc-item">
                                <div class="kp-doc-icon"><i class="uil uil-file-alt"></i></div>
                                <div class="kp-doc-info">
                                    <strong>KP Form #<?= htmlspecialchars(kp_form_label((int)$doc['form_number'])) ?> · v<?= (int)($doc['version_number'] ?? 1) ?></strong>
                                    <span><?= htmlspecialchars($doc['form_title']) ?></span>
                                    <small>Generated: <?= date('M d, Y h:i A', strtotime($doc['generated_at'])) ?></small>
                                </div>
                                <div class="kp-doc-actions">
                                    <span class="kp-doc-status <?= strtolower($doc['status']) ?>"><?= htmlspecialchars($doc['status']) ?></span>
                                    <?php if ($doc['file_path'] && file_exists($doc['file_path'])): ?>
                                    <a href="kp_download.php?doc_id=<?= $doc['id'] ?>" class="kp-btn kp-btn-sm kp-btn-outline" title="Download DOCX">
                                        <i class="uil uil-download-alt"></i> Download
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ TAB: Timeline ═══ -->
                <div class="kp-tab-content" id="tab-timeline">
                    <div class="kp-section-card">
                        <h3 class="kp-section-title">Case Timeline</h3>
                        <p class="kp-section-desc">Complete audit trail of all events for this case.</p>

                        <?php if (!empty($timeline)): ?>
                        <div class="kp-full-timeline">
                            <?php foreach ($timeline as $evt): ?>
                            <div class="kp-ftl-item">
                                <div class="kp-ftl-line">
                                    <div class="kp-ftl-dot" style="background: <?= kp_status_color($evt['status'] ?? 'filed') ?>;"></div>
                                </div>
                                <div class="kp-ftl-content">
                                    <div class="kp-ftl-header">
                                        <strong><?= htmlspecialchars($evt['title']) ?></strong>
                                        <span class="kp-ftl-badge"><?= htmlspecialchars($evt['event_type']) ?></span>
                                    </div>
                                    <?php if ($evt['details']): ?>
                                    <div class="kp-ftl-details"><?= nl2br(htmlspecialchars($evt['details'])) ?></div>
                                    <?php endif; ?>
                                    <div class="kp-ftl-time"><?= date('M d, Y h:i A', strtotime($evt['event_at'])) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="kp-empty-state">
                            <i class="uil uil-clock-three"></i>
                            <p>No events recorded yet. Events will appear here as the case progresses.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /.kp-main-panel -->
        </div><!-- /.kp-layout -->

        <?php endif; ?>
    </div><!-- /.page-body -->
</div><!-- /.main -->

<script>
// ── Tab Switching ──
function switchTab(tabName) {
    const panel = document.getElementById(`tab-${tabName}`);
    if (!panel) return;
    document.querySelectorAll('.kp-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.kp-tab-content').forEach(t => t.classList.remove('active'));
    const btn = document.querySelector(`.kp-tab[data-tab="${tabName}"]`);
    if (btn) btn.classList.add('active');
    panel.classList.add('active');
}

document.querySelectorAll('.kp-tab').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});

// ── Review form: show/hide missing items field ──
const reviewDecision = document.getElementById('reviewDecision');
const missingField = document.getElementById('missingItemsField');
if (reviewDecision && missingField) {
    reviewDecision.addEventListener('change', () => {
        missingField.style.display = reviewDecision.value === 'additional_info_required' ? 'block' : 'none';
        const natureField = document.getElementById('natureField');
        if (natureField) {
            natureField.style.display = reviewDecision.value === 'accepted' ? 'block' : 'none';
        }
        const coverageFields = document.getElementById('coverageFields');
        const coverageReason = document.getElementById('coverageReason');
        if (coverageFields && coverageReason) {
            const excluded = reviewDecision.value === 'not_eligible';
            coverageFields.style.display = excluded ? 'block' : 'none';
            coverageReason.required = excluded;
        }
    });
}

// ── Auto-select tab from URL hash ──
const hash = window.location.hash.replace('#', '');
const requestedTab = <?= json_encode($requestedTab, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const initialTab = hash || requestedTab;
if (initialTab) switchTab(initialTab);

// ── Form confirmations (styled dialog from includes/header.php) ──
// Searches and document generation do not change the case, so they skip this.
const kpConfirmTexts = {
    set_nature:           { title: 'Update the nature of the case?', message: 'This changes how the case is counted in the KP Monitoring Report.', confirmText: 'Update' },
    review:               { title: 'Submit this decision?', message: 'Decision: {choice}. The resident will see the updated status of the complaint in the app.', confirmText: 'Submit Decision' },
    schedule:             { title: 'Schedule this hearing?', message: 'The date, time and place will be saved to the case. Notices for this hearing can then be generated.', confirmText: 'Schedule' },
    schedule_explanation: { title: 'Schedule the explanation hearing?', message: 'The absent party will be given a chance to explain why they did not appear.', confirmText: 'Schedule' },
    record_outcome:       { title: 'Record this hearing outcome?', message: 'Outcome: {choice}. The case will move to the next step based on this outcome.', confirmText: 'Record Outcome' },
    record_service:       { title: 'Record service of the summons?', message: 'The date and manner of service will be printed on the Officer\'s Return.', confirmText: 'Record Service' },
    pangkat:              { title: 'Record the Pangkat members?', message: 'The chosen members, chairman and secretary will be saved and used on the conciliation forms.', confirmText: 'Record Pangkat' },
    extend_conciliation:  { title: 'Extend conciliation by 15 days?', message: 'The Pangkat may extend the conciliation period only once, for a meritorious case.', confirmText: 'Extend' },
    record:               { title: 'Record the amicable settlement?', message: 'The 10-day repudiation period starts from the settlement date.', confirmText: 'Record Settlement' },
    repudiate:            { title: 'Record the repudiation?', message: 'The settlement will be set aside and the complainant will be certified to file the case in court (KP Form 20).', confirmText: 'Record Repudiation', danger: true },
    confirm_final:        { title: 'Confirm final and executory?', message: 'Confirm only if no repudiation was filed within the 10-day period. The settlement or award will then have the force of a final court judgment.', confirmText: 'Confirm Final' },
    record_transmittal:   { title: 'Record the transmittal to court?', message: 'This records the date the settlement or award was sent to the city/municipal court.', confirmText: 'Record Transmittal' },
    agree:                { title: 'Record the agreement for arbitration?', message: 'Either party may repudiate the agreement within 5 days. The arbitration hearing can be scheduled after that period.', confirmText: 'Record Agreement' },
    repudiate_agreement:  { title: 'Record repudiation of the arbitration agreement?', message: 'The agreement will be set aside and the complainant will be certified to file the case in court.', confirmText: 'Record Repudiation', danger: true },
    award:                { title: 'Record the arbitration award?', message: 'The award becomes final after 10 days unless a petition to nullify it is filed in court.', confirmText: 'Record Award' },
    start_monitoring:     { title: 'Begin compliance monitoring?', message: 'Track whether the parties carry out the settlement or award.', confirmText: 'Begin Monitoring' },
    mark_complied:        { title: 'Close the case as complied?', message: 'The parties fully carried out the settlement or award. The case will be closed.', confirmText: 'Close Case' },
    partial_compliance:   { title: 'Record partial compliance?', message: 'Your note will be added to the case. The case stays under monitoring.', confirmText: 'Save Note' },
    non_compliance:       { title: 'Record non-compliance?', message: 'The case will move to Execution so the prevailing party can file a Motion for Execution.', confirmText: 'Record' },
    file_motion:          { title: 'File the Motion for Execution?', message: 'A hearing on the motion must be held within 5 days.', confirmText: 'File Motion' },
    schedule_hearing:     { title: 'Schedule the execution hearing?', message: 'The obliged party will be asked why they have not complied.', confirmText: 'Schedule' },
    complied:             { title: 'Record voluntary compliance?', message: 'The obliged party complied after the motion was filed.', confirmText: 'Record' },
    issue_notice:         { title: 'Issue the Notice of Execution?', message: 'Use this only if the obliged party still has not complied 5 days after the hearing.', confirmText: 'Issue Notice', danger: true },
    mark_executed:        { title: 'Mark execution as completed?', message: 'The settlement or award has been enforced.', confirmText: 'Mark Completed' },
    close_lapsed:         { title: 'Close the case?', message: 'The 6-month barangay execution period has passed. The settlement or award can now be enforced only through the court.', confirmText: 'Close Case', danger: true },
    close_case:           { title: 'Close the case?', message: 'The case will be closed and its outcome recorded.', confirmText: 'Close Case' }
};

function kpFormAction(form, submitter) {
    if (submitter && submitter.name === 'action') return submitter.value;
    const field = form.querySelector('[name="action"]');
    if (field) return field.value;
    if (form.action.includes('kp_review.php')) return 'review';
    if (form.action.includes('kp_pangkat.php')) return 'pangkat';
    return '';
}

function kpSelectedChoice(form, action) {
    const name = action === 'record_outcome' ? 'outcome' : (action === 'review' ? 'decision' : 'action');
    const select = form.querySelector('select[name="' + name + '"]');
    return select && select.selectedIndex > 0 ? select.options[select.selectedIndex].text.trim() : '';
}

document.querySelectorAll('form').forEach(form => {
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    form.addEventListener('submit', async function (e) {
        if (this.dataset.confirmed === '1') return;
        const action = kpFormAction(this, e.submitter);
        if (action === 'generate') return;
        e.preventDefault();

        const submitter = e.submitter;
        const buttonText = submitter ? submitter.textContent.trim() : '';
        const text = kpConfirmTexts[action] || {};
        const choice = kpSelectedChoice(this, action);
        const options = {
            title: text.title || (buttonText ? buttonText + '?' : 'Save this action?'),
            message: (text.message || 'This will be saved to the case record.').replace('{choice}', choice || 'as selected'),
            confirmText: text.confirmText || buttonText || 'Confirm',
            cancelText: 'Cancel',
            danger: !!text.danger
        };
        const ok = window.AdminConfirm
            ? await window.AdminConfirm.confirm(options)
            : window.confirm(options.title + '\n\n' + options.message);
        if (!ok) return;

        this.dataset.confirmed = '1';
        if (this.requestSubmit) {
            this.requestSubmit(submitter || undefined);
        } else {
            this.submit();
        }
    });
});
</script>

<style>
/* ═══════════════════════════════════════════════════════════════
   KP Case Process Workspace Styles
   ═══════════════════════════════════════════════════════════════ */

.kp-body { padding: 20px 24px 40px; }

/* ── Page Header ── */
.kp-page-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.kp-back-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; text-decoration: none; color: #1d4ed8; background: #eff6ff; border: 1px solid #dbeafe; border-radius: 9px; padding: 8px 13px; transition: all .2s; }
.kp-back-btn:hover { background: #dbeafe; }
.kp-case-header { display: flex; align-items: center; gap: 12px; }
.kp-case-id { font-size: 18px; font-weight: 800; color: #1e293b; letter-spacing: -0.5px; }
.kp-status-badge { display: inline-flex; font-size: 11px; font-weight: 700; padding: 5px 12px; border-radius: 999px; letter-spacing: 0.3px; }

/* ── Deadline Alert ── */
.kp-deadline-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; margin-bottom: 16px; font-size: 13px; font-weight: 600; color: #c2410c; animation: kp-pulse 2s infinite; }
@keyframes kp-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }

/* ── Stage Progress Bar ── */
.kp-stage-bar { display: flex; align-items: center; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; overflow-x: auto; gap: 0; }
.kp-stage-item { display: flex; flex-direction: column; align-items: center; gap: 6px; min-width: 70px; flex-shrink: 0; }
.kp-stage-dot { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; transition: all .3s; }
.kp-stage-item.completed .kp-stage-dot { background: #dcfce7; color: #16a34a; }
.kp-stage-item.active .kp-stage-dot { background: #1d4ed8; color: #fff; box-shadow: 0 0 0 4px #dbeafe; }
.kp-stage-item.upcoming .kp-stage-dot { background: #f1f5f9; color: #94a3b8; }
.kp-stage-label { font-size: 10px; font-weight: 600; color: #64748b; text-align: center; white-space: nowrap; }
.kp-stage-item.active .kp-stage-label { color: #1d4ed8; font-weight: 700; }
.kp-stage-line { flex: 1; height: 2px; background: #e2e8f0; min-width: 16px; }
.kp-stage-line.completed { background: #86efac; }

/* ── Main Layout ── */
.kp-layout { display: grid; grid-template-columns: 280px 1fr; gap: 20px; }

/* ── Left Panel (Sidebar) ── */
.kp-sidebar-panel { display: flex; flex-direction: column; gap: 14px; }
.kp-panel-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; }
.kp-panel-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #64748b; margin-bottom: 12px; }
.kp-info-row { display: flex; flex-direction: column; gap: 2px; margin-bottom: 8px; }
.kp-info-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; }
.kp-info-value { font-size: 13px; font-weight: 600; color: #1e293b; }
.kp-info-sm { font-size: 11.5px; font-weight: 400; color: #64748b; }
.kp-info-warn { color: #ea580c; }
.kp-divider { height: 1px; background: #f1f5f9; margin: 6px 0; }

.kp-quick-actions { display: flex; flex-direction: column; gap: 6px; }
.kp-action-btn { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: all .15s; }
.kp-action-view { color: #1d4ed8; background: #eff6ff; } .kp-action-view:hover { background: #dbeafe; }
.kp-action-edit { color: #0f766e; background: #f0fdf4; } .kp-action-edit:hover { background: #dcfce7; }

.kp-stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.kp-stat { text-align: center; padding: 8px; background: #f8fafc; border-radius: 8px; }
.kp-stat-num { display: block; font-size: 18px; font-weight: 800; color: #1e293b; }
.kp-stat-label { font-size: 10px; font-weight: 600; text-transform: uppercase; color: #94a3b8; }

/* ── Right Panel (Tabs) ── */
.kp-main-panel { min-width: 0; }

.kp-tabs { display: flex; gap: 2px; background: #f1f5f9; border-radius: 10px; padding: 4px; margin-bottom: 16px; overflow-x: auto; flex-wrap: nowrap; }
.kp-tab { display: inline-flex; align-items: center; gap: 5px; padding: 8px 14px; font-size: 12px; font-weight: 600; color: #64748b; background: transparent; border: none; border-radius: 8px; cursor: pointer; transition: all .2s; white-space: nowrap; }
.kp-tab:hover { color: #1e293b; background: #e2e8f0; }
.kp-tab.active { color: #1d4ed8; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.kp-tab i { font-size: 14px; }

.kp-tab-content { display: none; }
.kp-tab-content.active { display: block; animation: kp-fadeIn .25s ease; }
@keyframes kp-fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* ── Section Cards ── */
.kp-section-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
.kp-section-title { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px; }
.kp-section-desc { font-size: 13px; color: #64748b; margin-bottom: 16px; }
.kp-sub-title { font-size: 13px; font-weight: 700; color: #475569; margin: 20px 0 10px 0; padding-top: 14px; border-top: 1px solid #f1f5f9; }

/* ── Next Steps ── */
.kp-next-steps { border-left: 4px solid #1d4ed8; }
.kp-next-body { font-size: 13px; color: #374151; line-height: 1.6; }
.kp-next-body ol { padding-left: 18px; margin: 8px 0; }
.kp-next-action { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; color: #fff; background: #1d4ed8; border: none; border-radius: 9px; cursor: pointer; transition: all .2s; }
.kp-next-action:hover { background: #1e40af; }
.kp-action-row { display: flex; flex-wrap: wrap; gap: 8px; }

/* ── Forms ── */
.kp-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.kp-field label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; }
.kp-field label span { color: #dc2626; }
.kp-field input, .kp-field select, .kp-field textarea { width: 100%; padding: 9px 12px; font-size: 13px; border: 1px solid #e2e8f0; border-radius: 9px; background: #fafbff; color: #1f2937; outline: none; resize: vertical; box-sizing: border-box; }
.kp-field input:focus, .kp-field select:focus, .kp-field textarea:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px #dbeafe; }
.kp-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

.kp-form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }

.kp-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; font-size: 12.5px; font-weight: 600; border: none; border-radius: 9px; cursor: pointer; transition: all .15s; }
.kp-btn-primary { background: #1d4ed8; color: #fff; } .kp-btn-primary:hover { background: #1e40af; }
.kp-btn-sm { padding: 6px 10px; font-size: 11px; }
.kp-btn-outline { background: #fff; color: #1d4ed8; border: 1px solid #dbeafe; } .kp-btn-outline:hover { background: #eff6ff; }
.kp-select-sm { padding: 6px 10px; font-size: 12px; border: 1px solid #e2e8f0; border-radius: 7px; background: #fff; }

/* ── Info Boxes ── */
.kp-info-box { display: flex; gap: 12px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; align-items: flex-start; }
.kp-info-box i { font-size: 20px; color: #3b82f6; margin-top: 2px; }
.kp-info-box p { margin: 4px 0 0 0; font-size: 12px; color: #64748b; }
.kp-info-success { background: #f0fdf4; border-color: #bbf7d0; }
.kp-info-success i { color: #16a34a; }

/* ── Narrative ── */
.kp-narrative { font-size: 13px; line-height: 1.65; color: #374151; padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #f1f5f9; }

/* ── Review History ── */
.kp-review-history { display: flex; flex-direction: column; gap: 8px; }
.kp-review-item { padding: 12px; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 8px; }
.kp-review-decision { display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
.kp-review-date { font-size: 11px; color: #94a3b8; }
.kp-review-notes { font-size: 12px; color: #475569; margin-top: 6px; }
.kp-review-missing { font-size: 12px; color: #b91c1c; margin-top: 6px; padding: 8px; background: #fff1f2; border-radius: 6px; }

/* ── Hearing List ── */
.kp-hearing-list { display: flex; flex-direction: column; gap: 10px; }
.kp-hearing-item { display: flex; gap: 14px; padding: 12px; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 10px; align-items: flex-start; }
.kp-hearing-date-badge { display: flex; flex-direction: column; align-items: center; min-width: 44px; padding: 6px 8px; background: #1d4ed8; color: #fff; border-radius: 8px; }
.kp-hb-day { font-size: 16px; font-weight: 800; line-height: 1; }
.kp-hb-month { font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.kp-hearing-info { flex: 1; }
.kp-hearing-title { font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 4px; }
.kp-hearing-meta { display: flex; gap: 14px; font-size: 11.5px; color: #64748b; }
.kp-hearing-meta i { font-size: 13px; }
.kp-hearing-outcome { font-size: 12px; color: #059669; margin-top: 6px; font-weight: 600; }
.kp-hearing-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

/* ── Pangkat Members ── */
.kp-pangkat-list { display: flex; flex-direction: column; gap: 8px; }
.kp-pangkat-member { display: flex; gap: 12px; align-items: center; padding: 10px; background: #f8fafc; border-radius: 8px; }
.kp-pm-avatar { width: 36px; height: 36px; background: #dbeafe; color: #1d4ed8; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.kp-pm-role { display: block; font-size: 11px; color: #64748b; }

/* ── Settlement Cards ── */
.kp-settlement-card { padding: 14px; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 10px; margin-bottom: 10px; }
.kp-stl-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13px; }
.kp-stl-date { font-size: 11px; color: #94a3b8; }
.kp-stl-terms { font-size: 12.5px; color: #374151; line-height: 1.5; margin-bottom: 10px; }
.kp-stl-footer { display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; color: #64748b; }
.kp-badge-red { background: #fee2e2; color: #b91c1c; padding: 3px 10px; border-radius: 999px; font-weight: 700; font-size: 10px; }
.kp-badge-green { background: #dcfce7; color: #15803d; padding: 3px 10px; border-radius: 999px; font-weight: 700; font-size: 10px; }
.kp-badge-yellow { background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 999px; font-weight: 700; font-size: 10px; }

/* ── Document Buttons ── */
.kp-doc-btn-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
.kp-doc-btn { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all .15s; text-align: left; width: 100%; }
.kp-doc-btn:hover { background: #eff6ff; border-color: #93c5fd; }
.kp-doc-btn:disabled { opacity: .6; cursor: not-allowed; }
.kp-doc-btn:disabled:hover { background: #f8fafc; border-color: #e2e8f0; }
.kp-doc-btn i { font-size: 18px; color: #1d4ed8; }
.kp-doc-btn span { font-size: 12px; font-weight: 700; color: #1e293b; }
.kp-doc-btn small { font-size: 10.5px; color: #64748b; }

.kp-doc-list { display: flex; flex-direction: column; gap: 8px; }
.kp-doc-item { display: flex; gap: 12px; align-items: center; padding: 12px; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 10px; }
.kp-doc-icon { width: 36px; height: 36px; background: #dbeafe; color: #1d4ed8; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.kp-doc-info { flex: 1; }
.kp-doc-info strong { font-size: 13px; color: #1e293b; display: block; }
.kp-doc-info span { font-size: 12px; color: #64748b; }
.kp-doc-info small { display: block; font-size: 10.5px; color: #94a3b8; margin-top: 2px; }
.kp-doc-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.kp-doc-status { font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 999px; text-transform: uppercase; }
.kp-doc-status.draft { background: #fef3c7; color: #92400e; }
.kp-doc-status.final { background: #dcfce7; color: #15803d; }

/* ── Timeline ── */
.kp-mini-timeline { display: flex; flex-direction: column; gap: 10px; }
.kp-tl-item { display: flex; gap: 10px; align-items: flex-start; }
.kp-tl-dot { width: 8px; height: 8px; background: #3b82f6; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
.kp-tl-title { font-size: 12.5px; font-weight: 600; color: #1e293b; }
.kp-tl-time { font-size: 10.5px; color: #94a3b8; }
.kp-see-all { background: none; border: none; color: #1d4ed8; font-size: 12px; font-weight: 600; cursor: pointer; margin-top: 6px; }

.kp-full-timeline { display: flex; flex-direction: column; gap: 0; }
.kp-ftl-item { display: flex; gap: 14px; }
.kp-ftl-line { display: flex; flex-direction: column; align-items: center; width: 20px; flex-shrink: 0; }
.kp-ftl-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.kp-ftl-line::after { content: ''; width: 2px; flex: 1; background: #e2e8f0; margin-top: 4px; }
.kp-ftl-item:last-child .kp-ftl-line::after { display: none; }
.kp-ftl-content { padding-bottom: 18px; flex: 1; }
.kp-ftl-header { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.kp-ftl-header strong { font-size: 13px; color: #1e293b; }
.kp-ftl-badge { font-size: 10px; font-weight: 600; padding: 2px 8px; background: #f1f5f9; color: #475569; border-radius: 999px; }
.kp-ftl-details { font-size: 12px; color: #64748b; margin-top: 4px; line-height: 1.5; }
.kp-ftl-time { font-size: 10.5px; color: #94a3b8; margin-top: 4px; }

/* ── Empty & Error States ── */
.kp-empty-state { text-align: center; padding: 32px 16px; }
.kp-empty-state i { font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 8px; }
.kp-empty-state p { font-size: 13px; color: #64748b; }
.kp-error-card { text-align: center; padding: 40px 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
.kp-error-card i { font-size: 36px; color: #f87171; display: block; margin-bottom: 8px; }
.kp-error-title { font-size: 14px; color: #1e293b; margin-bottom: 10px; }
.kp-back-link { font-size: 12.5px; color: #1d4ed8; text-decoration: none; font-weight: 600; }
.kp-btn-ghost { background: #f1f5f9; color: #334155; text-decoration: none; } .kp-btn-ghost:hover { background: #e2e8f0; }
.kp-finder-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.kp-finder-input { flex: 1; min-width: 220px; margin: 0; }
.kp-finder-meta { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 14px 0 8px; font-size: 12.5px; color: #64748b; }
.kp-finder-meta a { color: #1d4ed8; text-decoration: none; font-weight: 600; }
.kp-finder-list { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
.kp-finder-list + .gb-pg { border-top: 0; background: transparent; padding: 14px 0 0; }
.kp-finder-row { display: flex; align-items: center; gap: 14px; padding: 12px 14px; text-decoration: none; color: inherit; border-bottom: 1px solid #f1f5f9; transition: background .15s; }
.kp-finder-row:last-child { border-bottom: none; }
.kp-finder-row:hover { background: #f8fafc; }
.kp-finder-code { font-weight: 700; color: #1d4ed8; font-size: 13px; min-width: 64px; }
.kp-finder-parties { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.kp-finder-parties strong { font-size: 13px; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.kp-finder-parties span { font-size: 12px; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.kp-finder-arrow { font-size: 20px; color: #94a3b8; }
.kp-finder-empty { text-align: center; padding: 28px 12px; color: #64748b; font-size: 13px; }
.kp-finder-empty i { font-size: 32px; color: #cbd5e1; display: block; margin-bottom: 6px; }
@media (max-width: 640px) { .kp-finder-row { flex-wrap: wrap; } .kp-finder-arrow { display: none; } }

.kp-current-status { display: flex; align-items: center; gap: 14px; }
.kp-big-badge { padding: 8px 18px; border-radius: 999px; font-size: 13px; font-weight: 700; }
.kp-stage-text { font-size: 12px; color: #64748b; }

/* ── Responsive ── */
@media (max-width: 1024px) {
    .kp-layout { grid-template-columns: 1fr; }
    .kp-sidebar-panel { order: 2; }
    .kp-main-panel { order: 1; }
}
@media (max-width: 768px) {
    .kp-body { padding: 14px; }
    .kp-page-head { flex-direction: column; align-items: flex-start; }
    .kp-field-row { grid-template-columns: 1fr; }
    .kp-tabs { gap: 1px; padding: 3px; }
    .kp-tab { padding: 7px 10px; font-size: 11px; }
    .kp-tab i { display: none; }
    .kp-doc-btn-grid { grid-template-columns: 1fr; }
    .kp-hearing-item { flex-direction: column; }
    .kp-hearing-actions { width: 100%; }
}

/* ── Print Media Styles ── */
@media print {
    body { background: #fff !important; color: #000 !important; font-size: 11pt !important; }
    .sidebar, .topbar, .kp-back-btn, .kp-tabs, .kp-doc-btn-grid, .kp-form-actions, form, .kp-next-action, .kp-next-steps, footer, .main-footer { display: none !important; }
    .main { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
    .kp-body { padding: 0 !important; }
    .kp-layout { display: block !important; }
    .kp-sidebar-panel { width: 100% !important; margin-bottom: 20px !important; }
    .kp-panel-card, .kp-section-card { border: 1px solid #94a3b8 !important; box-shadow: none !important; margin-bottom: 16px !important; break-inside: avoid; }
    .kp-tab-content { display: none !important; }
    .kp-tab-content#tab-overview { display: block !important; }
    .kp-status-badge { border: 1px solid #000 !important; color: #000 !important; background: none !important; }
}
</style>
