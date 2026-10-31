<?php
/**
 * GabayBatas — Public Resident Case Tracker
 * Barangay Muñoz, Catbalogan City, Samar
 *
 * Allows complainants and respondents to safely check their case progress,
 * next hearing date/time, and general status without exposing confidential
 * notes or internal mediation discussions.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/kp.php';

$searchQuery = trim($_GET['case_id'] ?? $_GET['ref'] ?? '');
$foundCase = null;
$error = '';
$nextHearing = null;
$milestones = [];

if ($searchQuery !== '') {
    $stmt = $conn->prepare("SELECT id, case_id, name, complained_person, case_type, barangay, status, kp_status, kp_disposition, date_submitted, mediation_deadline, conciliation_deadline FROM cases WHERE case_id = ? OR CAST(id AS CHAR) = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $searchQuery, $searchQuery);
        $stmt->execute();
        $res = $stmt->get_result();
        $foundCase = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if ($foundCase) {
        $caseRowId = (int)$foundCase['id'];
        
        // Fetch next scheduled hearing
        $hStmt = $conn->prepare("SELECT stage AS hearing_type, hearing_date, hearing_time, location AS venue FROM kp_hearings WHERE case_id = ? AND hearing_date >= CURDATE() AND (outcome IS NULL OR outcome = '') ORDER BY hearing_date ASC, hearing_time ASC LIMIT 1");
        if ($hStmt) {
            $hStmt->bind_param('i', $caseRowId);
            $hStmt->execute();
            $hRes = $hStmt->get_result();
            if ($hRes && ($hRow = $hRes->fetch_assoc())) {
                $nextHearing = $hRow;
            }
            $hStmt->close();
        }

        // Fetch safe public timeline events
        $mStmt = $conn->prepare("SELECT event_type, title AS summary, created_at FROM kp_timeline_events WHERE case_id = ? AND event_type <> 'details_corrected' ORDER BY id DESC LIMIT 8");
        if ($mStmt) {
            $mStmt->bind_param('i', $caseRowId);
            $mStmt->execute();
            $mRes = $mStmt->get_result();
            if ($mRes) {
                while ($mRow = $mRes->fetch_assoc()) {
                    $milestones[] = $mRow;
                }
            }
            $mStmt->close();
        }
    } else {
        $error = 'No case found matching "' . htmlspecialchars($searchQuery) . '". Please check your Case Number and try again.';
    }
}

// Stage progress computation
$kpStatus = $foundCase['kp_status'] ?? 'filed';
$kpInfo = kp_status_info($kpStatus);
$currentStage = $kpInfo['stage'] ?? 'intake';

$stages = [
    'intake'       => ['title' => 'Intake / Review', 'waray' => 'Pagduso'],
    'mediation'    => ['title' => 'Mediation',       'waray' => 'Pagpatunga'],
    'conciliation' => ['title' => 'Conciliation',    'waray' => 'Pangkat Paghusay'],
    'settlement'   => ['title' => 'Settlement',      'waray' => 'Kasabutan'],
    'closed'       => ['title' => 'Resolution',      'waray' => 'Pagtapos'],
];

$stageKeys = array_keys($stages);
$currentStageIndex = 0;
if ($currentStage === 'arbitration') {
    $currentStageIndex = 2;
} elseif ($currentStage === 'compliance' || $currentStage === 'execution') {
    $currentStageIndex = 3;
} else {
    $idx = array_search($currentStage, $stageKeys, true);
    if ($idx !== false) {
        $currentStageIndex = $idx;
    }
    if (in_array($kpStatus, ['settlement_final', 'certified_to_court', 'dismissed', 'closed', 'execution_completed', 'not_eligible'], true)) {
        $currentStageIndex = 4;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your KP Case — GabayBatas Barangay Muñoz</title>
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unicons.iconscout.com/release/v4.0.8/css/line.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Source Sans 3', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #eef2f8;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .flag-bar { height: 4px; background: linear-gradient(90deg, #1a3a6b 0 33%, #b91c1c 33% 66%, #c9a227 66%); }

        .track-nav {
            background: radial-gradient(circle at 10% 0%, rgba(255,255,255,.10), transparent 40%), linear-gradient(160deg, #1a3a6b 0%, #0e2242 60%, #0b1a33 100%);
            color: #ffffff;
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            box-shadow: 0 6px 20px rgba(14, 34, 66, .18);
        }
        .track-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: #fff; min-width: 0; }
        .track-seal { width: 46px; height: 46px; border-radius: 50%; background: #fff; padding: 3px; flex-shrink: 0; box-shadow: 0 0 0 2px rgba(255,255,255,.18); }
        .track-seal img { width: 100%; height: 100%; object-fit: contain; border-radius: 50%; }
        .track-brand-text { margin-left: 4px; line-height: 1.3; }
        .track-brand-text small { display: block; font-size: 10.5px; letter-spacing: 1.4px; text-transform: uppercase; color: rgba(255,255,255,.65); }
        .track-brand-text h1 { font-family: 'Source Serif 4', serif; font-size: 18px; font-weight: 700; }
        .track-brand-text p { font-size: 12px; color: rgba(255,255,255,.75); }
        .track-app { display: inline-flex; align-items: center; gap: 9px; margin-left: auto; padding-right: 6px; color: #fff; }
        .track-app i { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; background: rgba(255,255,255,.14); font-size: 17px; }
        .track-app b { font-family: 'Source Serif 4', serif; font-size: 16px; }
        .track-login-btn {
            background: #fff;
            color: #0e2242;
            padding: 9px 16px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: transform .15s, box-shadow .15s;
        }
        .track-login-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,.2); }

        .track-container {
            max-width: 860px;
            margin: 32px auto 48px;
            padding: 0 16px;
            width: 100%;
            flex: 1;
        }

        .track-hero {
            background: #ffffff;
            border-radius: 18px;
            padding: 32px 28px;
            box-shadow: 0 18px 50px rgba(14, 34, 66, .10);
            border: 1px solid #e2e8f0;
            text-align: center;
            margin-bottom: 24px;
        }
        .track-hero-icon { width: 52px; height: 52px; margin: 0 auto 12px; border-radius: 15px; display: grid; place-items: center; background: #fff7ed; color: #c2560c; font-size: 25px; }
        .track-hero h2 { font-family: 'Source Serif 4', serif; font-size: 26px; color: #0f172a; margin-bottom: 6px; }
        .track-hero p { font-size: 13.5px; color: #64748b; margin-bottom: 20px; }
        .track-form {
            display: flex;
            max-width: 520px;
            margin: 0 auto;
            gap: 8px;
        }
        .track-input {
            flex: 1;
            padding: 12px 16px;
            border: 1.5px solid #dbe3ef;
            border-radius: 12px;
            background: #f8fafc;
            font-size: 15px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }
        .track-input:focus { border-color: #24508f; background: #fff; box-shadow: 0 0 0 4px rgba(36, 80, 143, .12); }
        .track-submit-btn {
            background: linear-gradient(135deg, #24508f, #0e2242);
            color: #ffffff;
            border: none;
            padding: 12px 22px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .track-submit-btn:hover { filter: brightness(1.08); }

        .track-alert {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .track-result {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .track-header {
            background: linear-gradient(135deg, #1a3a6b 0%, #24508f 100%);
            color: #ffffff;
            padding: 22px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .track-case-id { font-family: 'Source Serif 4', serif; font-size: 24px; font-weight: 700; letter-spacing: 0.5px; }
        .track-case-type { font-size: 13px; opacity: 0.9; margin-top: 2px; }
        .track-status-pill {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(4px);
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
        }

        .track-stepper {
            padding: 28px 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .stepper-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
        }
        .stepper-steps::before {
            content: '';
            position: absolute;
            top: 18px;
            left: 20px;
            right: 20px;
            height: 3px;
            background: #e2e8f0;
            z-index: 1;
        }
        .stepper-step {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 18%;
            text-align: center;
        }
        .step-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #ffffff;
            border: 3px solid #cbd5e1;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 8px;
            transition: all 0.2s;
        }
        .stepper-step.completed .step-circle {
            background: #10b981;
            border-color: #10b981;
            color: #fff;
        }
        .stepper-step.active .step-circle {
            background: #24508f;
            border-color: #93c5fd;
            color: #fff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.2);
        }
        .step-label { font-size: 12.5px; font-weight: 600; color: #334155; line-height: 1.2; }
        .step-waray { font-size: 11px; color: #64748b; margin-top: 2px; }

        .hearing-alert {
            margin: 20px 24px 0;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-left: 5px solid #24508f;
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .hearing-icon {
            font-size: 24px;
            color: #24508f;
            line-height: 1;
        }
        .hearing-info h3 { font-size: 15px; color: #1a3a6b; margin-bottom: 4px; }
        .hearing-info p { font-size: 13.5px; color: #1e293b; line-height: 1.4; }
        .hearing-meta {
            margin-top: 8px;
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            font-size: 13px;
            font-weight: 600;
            color: #1e40af;
        }

        .track-body { padding: 24px; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 14px 16px;
            border-radius: 8px;
        }
        .info-label { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 4px; }
        .info-value { font-size: 14px; font-weight: 600; color: #0f172a; }

        .timeline-box { margin-top: 8px; }
        .timeline-box h3 { font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .timeline-list { list-style: none; padding-left: 14px; border-left: 2px solid #e2e8f0; }
        .timeline-item { position: relative; padding-bottom: 16px; padding-left: 16px; }
        .timeline-item:last-child { padding-bottom: 0; }
        .timeline-dot {
            position: absolute;
            left: -21px;
            top: 3px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #24508f;
            border: 2px solid #fff;
            box-shadow: 0 0 0 2px #bfdbfe;
        }
        .timeline-item-title { font-size: 13px; font-weight: 600; color: #0f172a; }
        .timeline-item-date { font-size: 11.5px; color: #64748b; margin-top: 2px; }

        .help-box {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
            font-size: 12.5px;
            color: #475569;
            line-height: 1.5;
            display: flex;
            gap: 10px;
        }
        .help-box i { font-size: 18px; color: #64748b; }

        .track-footer {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            background: #fff;
        }

        @media (max-width: 640px) {
            .stepper-steps { flex-direction: column; gap: 14px; }
            .stepper-steps::before { display: none; }
            .stepper-step { width: 100%; flex-direction: row; gap: 12px; text-align: left; }
            .step-circle { margin-bottom: 0; }
            .track-form { flex-direction: column; }
            .track-nav { padding: 12px 16px; }
            .track-app, .track-seal + .track-seal, .track-brand-text small { display: none; }
        }
    </style>
</head>
<body>

    <div class="flag-bar"></div>
    <header class="track-nav">
        <a href="track.php" class="track-brand">
            <span class="track-seal"><img src="images/seal_city_catbalogan.png" alt="Seal of the City of Catbalogan"></span>
            <span class="track-seal"><img src="images/seal_barangay_munoz.png" alt="Seal of Barangay Muñoz"></span>
            <div class="track-brand-text">
                <small>Republic of the Philippines</small>
                <h1>Barangay Muñoz</h1>
                <p>City of Catbalogan, Samar</p>
            </div>
        </a>
        <span class="track-app"><i class="uil uil-shield-check"></i><b>GabayBatas</b></span>
        <a href="login.php" class="track-login-btn">
            <i class="uil uil-sign-in-alt"></i> Official sign in
        </a>
    </header>

    <main class="track-container">
        <div class="track-hero">
            <div class="track-hero-icon"><i class="uil uil-search-alt"></i></div>
            <h2>Track Your Case Progress</h2>
            <p>Enter your Barangay Case Number or tracking ID below to check hearing schedules and status.</p>
            <form method="GET" action="track.php" class="track-form">
                <input type="text" name="case_id" class="track-input" placeholder="e.g. C-0022 or 22" value="<?= htmlspecialchars($searchQuery) ?>" required autofocus>
                <button type="submit" class="track-submit-btn">
                    <i class="uil uil-search"></i> Check Status
                </button>
            </form>
        </div>

        <?php if ($error !== ''): ?>
            <div class="track-alert">
                <i class="uil uil-exclamation-triangle" style="font-size:20px;"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <?php if ($foundCase): ?>
            <div class="track-result">
                <div class="track-header">
                    <div>
                        <div class="track-case-id"><?= htmlspecialchars($foundCase['case_id'] ?: 'Case #' . $foundCase['id']) ?></div>
                        <div class="track-case-type"><?= htmlspecialchars((string)($foundCase['case_type'] ?: 'Barangay Dispute')) ?> • <?= htmlspecialchars((string)($foundCase['barangay'] ?: 'Barangay Muñoz')) ?></div>
                    </div>
                    <div>
                        <span class="track-status-pill"><?= htmlspecialchars($kpInfo['label'] ?? ucfirst(str_replace('_', ' ', $kpStatus))) ?></span>
                        <?php if (!empty($foundCase['kp_disposition'])): ?>
                        <div class="track-case-type" style="margin-top:6px;text-align:right;"><?= htmlspecialchars(kp_case_outcome_label($conn, (int)$foundCase['id'], $foundCase['kp_disposition'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="track-stepper">
                    <div class="stepper-steps">
                        <?php foreach ($stages as $key => $s): 
                            $stepIndex = array_search($key, $stageKeys, true);
                            $isCompleted = $stepIndex < $currentStageIndex;
                            $isActive = $stepIndex === $currentStageIndex;
                            $class = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
                        ?>
                        <div class="stepper-step <?= $class ?>">
                            <div class="step-circle">
                                <?php if ($isCompleted): ?>
                                    <i class="uil uil-check"></i>
                                <?php else: ?>
                                    <?= $stepIndex + 1 ?>
                                <?php endif; ?>
                            </div>
                            <div class="step-label"><?= htmlspecialchars($s['title']) ?></div>
                            <div class="step-waray"><?= htmlspecialchars($s['waray']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($nextHearing): ?>
                    <div class="hearing-alert">
                        <div class="hearing-icon"><i class="uil uil-calendar-alt"></i></div>
                        <div class="hearing-info">
                            <h3>Scheduled <?= htmlspecialchars(ucfirst($nextHearing['hearing_type'])) ?> Hearing</h3>
                            <p>Adunay nakatakda nga hearing para hini nga kaso. Palihug pag-abot sa saktong oras ha Barangay Hall.</p>
                            <div class="hearing-meta">
                                <span><i class="uil uil-calendar-alt"></i> <?= date('F j, Y (l)', strtotime($nextHearing['hearing_date'])) ?></span>
                                <span><i class="uil uil-clock"></i> <?= $nextHearing['hearing_time'] ? date('h:i A', strtotime($nextHearing['hearing_time'])) : 'TBD' ?></span>
                                <span><i class="uil uil-map-marker"></i> <?= htmlspecialchars($nextHearing['venue'] ?? 'Barangay Muñoz Hall') ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="track-body">
                    <div class="info-grid">
                        <div class="info-box">
                            <div class="info-label">Complainant (Nagsumbong)</div>
                            <div class="info-value"><?= htmlspecialchars(strtoupper($foundCase['name'] ?? 'N/A')) ?></div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Respondent (Gisumbong)</div>
                            <div class="info-value"><?= htmlspecialchars(strtoupper($foundCase['complained_person'] ?? 'N/A')) ?></div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Date Filed</div>
                            <div class="info-value"><?= $foundCase['date_submitted'] ? date('F j, Y', strtotime($foundCase['date_submitted'])) : 'N/A' ?></div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Current Stage</div>
                            <div class="info-value" style="color: <?= $kpInfo['color'] ?? '#24508f' ?>;">
                                <?= htmlspecialchars(ucfirst($currentStage)) ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($milestones)): ?>
                    <div class="timeline-box">
                        <h3><i class="uil uil-history"></i> Case Milestones</h3>
                        <div class="timeline-list">
                            <?php foreach ($milestones as $m): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-item-title"><?= htmlspecialchars((string)$m['summary']) ?></div>
                                <div class="timeline-item-date"><?= date('F j, Y • h:i A', strtotime($m['created_at'])) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="help-box">
                        <i class="uil uil-info-circle"></i>
                        <div>
                            <strong>Pahinumdom (Reminder):</strong> Kini nga tracker nagpakita lamang sa kinatibuk-ang estado ug eskedyul sa hearing subay sa Katarungang Pambarangay. Para sa pormal nga kopya sa mga dokumento o dugang nga tabang, palihug bisitaha ang Barangay Hall sa Barangay Muñoz, Catbalogan City.
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <footer class="track-footer">
        &copy; <?= date('Y') ?> GabayBatas — Katarungang Pambarangay System • Barangay Muñoz, Catbalogan City, Samar
    </footer>

</body>
</html>
