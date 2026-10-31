<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
gabaybatas_block_barangay_delete_requests();

$caseIdParam = trim((string)($_GET['id'] ?? ''));
// Opened from Case Process (Quick Actions): Back returns there.
$fromProcess = ($_GET['from'] ?? '') === 'process' && $caseIdParam !== '';
$backUrl = $fromProcess ? 'case_process.php?id=' . urlencode($caseIdParam) : 'cases.php';
$backLabel = $fromProcess ? 'Back to Case Process' : 'Back to Cases';
$case = null;
$error = '';
$canEditCase = false;

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
$residentColumn = $hasColumn('name') ? 'name' : ($hasColumn('resident_name') ? 'resident_name' : null);
$complainedColumn = $hasColumn('complained_person') ? 'complained_person' : ($hasColumn('respondent_name') ? 'respondent_name' : ($hasColumn('against_person') ? 'against_person' : null));
$typeColumn     = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn   = $hasColumn('status') ? 'status' : null;
$dateColumn     = $hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('created_at') ? 'created_at' : null);
$descColumn     = $hasColumn('description') ? 'description' : ($hasColumn('details') ? 'details' : null);

if ($caseIdParam === '') {
    $error = 'Missing case id.';
} elseif ($idColumn === null) {
    $error = 'Case table is missing an identifiable primary column.';
} else {
    $selectFields = [];
    if ($hasColumn('id')) { $selectFields[] = "id AS numeric_case_id"; }
    if ($hasColumn('case_id')) { $selectFields[] = "case_id AS case_code"; }
    if ($displayIdCol)   { $selectFields[] = "$displayIdCol AS display_case_id"; }
    if ($residentColumn) { $selectFields[] = "$residentColumn AS resident_name"; }
    if ($complainedColumn) { $selectFields[] = "$complainedColumn AS complained_person"; }
    if ($typeColumn)     { $selectFields[] = "$typeColumn AS case_type"; }
    if ($statusColumn)   { $selectFields[] = "$statusColumn AS case_status"; }
    if ($hasColumn("kp_status")) { $selectFields[] = "kp_status AS case_kp_status"; }
    if ($dateColumn)     { $selectFields[] = "$dateColumn AS case_date"; }
    if ($descColumn)     { $selectFields[] = "$descColumn AS case_description"; }
    if ($hasColumn('barangay')) { $selectFields[] = "barangay AS case_barangay"; }

    if (!$selectFields) {
        $error = 'No readable fields found in case table.';
    } else {
        $hasBothIdentifiers = $hasColumn('id') && $hasColumn('case_id');
        $whereClause = $hasBothIdentifiers
            ? '(id = ? OR case_id = ?)'
            : "$idColumn = ?";
        if ($hasColumn('archived_at')) {
            $whereClause .= ' AND archived_at IS NULL';
        }
        $sql = "SELECT " . implode(', ', $selectFields) . " FROM cases WHERE $whereClause LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($hasBothIdentifiers) {
                $stmt->bind_param('ss', $caseIdParam, $caseIdParam);
            } else {
                $stmt->bind_param('s', $caseIdParam);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $case = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if (!$case) {
            $error = 'Case not found.';
        }

        $evidence = [];
        if ($error === '' && $case) {
            $numId = (int)($case['numeric_case_id'] ?? (is_numeric($caseIdParam) ? $caseIdParam : 0));
            $codeId = (string)($case['case_code'] ?? $case['display_case_id'] ?? $caseIdParam);
            $evidenceStmt = $conn->prepare(
                'SELECT id, evidence_type, original_filename, mime_type, file_size
                 FROM api_case_evidence WHERE case_id = ? OR case_id = ? ORDER BY id ASC'
            );
            if ($evidenceStmt) {
                $evidenceStmt->bind_param('is', $numId, $codeId);
                $evidenceStmt->execute();
                $evidenceResult = $evidenceStmt->get_result();
                $evidence = $evidenceResult ? $evidenceResult->fetch_all(MYSQLI_ASSOC) : [];
                $evidenceStmt->close();
            }
        }

        if ($error === '' && $_SESSION['role'] === 'barangay') {
            $currentBarangay = gabaybatas_get_user_barangay($conn);
            if ($hasColumn('barangay') && $currentBarangay !== '') {
                $canEditCase = gabaybatas_barangay_matches($case, $currentBarangay);
            } else {
                $canEditCase = true;
            }
        } else {
            $canEditCase = true;
        }
    }
}
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">

    <div class="topbar">
        <div class="topbar-title">Case Details</div>
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

    <div class="page-body vc-body">

        <?php if ($error !== ''): ?>
        <div class="vc-head">
            <p class="vc-sub">Review complete information for the selected case.</p>
            <a href="<?= $backUrl ?>" class="vc-back-btn"><i class="uil uil-arrow-left"></i> <?= $backLabel ?></a>
        </div>
        <div class="card-box vc-card">
            <div class="vc-empty">
                <i class="uil uil-exclamation-circle"></i>
                <div class="vc-empty-title"><?= htmlspecialchars($error) ?></div>
                <a href="cases.php" class="vc-back-link">Return to case list</a>
            </div>
        </div>
        <?php else: ?>
            <?php
            $rawKp = trim((string)($case['case_kp_status'] ?? ''));
            $status = ($rawKp !== '' && function_exists('kp_status_label')) ? kp_status_label($rawKp) : trim((string)($case['case_status'] ?? 'N/A'));
            $statusColor = ($rawKp !== '' && function_exists('kp_status_color')) ? kp_status_color($rawKp) : (($case['case_status'] ?? '') === 'Resolved' ? '#16a34a' : ((($case['case_status'] ?? '') === 'In Progress') ? '#2563eb' : '#f59e0b'));
            $displayCaseId = gabaybatas_format_case_id($case['display_case_id'] ?? '', $caseIdParam);
            $dateRaw = (string)($case['case_date'] ?? '');
            $dateTs = strtotime($dateRaw);
            $dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : 'N/A');
            $residentName = (string)($case['resident_name'] ?? 'N/A');
            $respondentName = (string)($case['complained_person'] ?? 'N/A');
            $initial = static fn(string $n): string => strtoupper(substr(trim($n), 0, 1)) ?: '?';
            ?>

            <!-- Case summary and actions -->
            <div class="card-box vc-hero">
                <div class="vc-hero-main">
                    <span class="vc-hero-icon"><i class="uil uil-folder-open"></i></span>
                    <div>
                        <div class="vc-hero-label">Case ID</div>
                        <div class="vc-hero-id"><?= htmlspecialchars($displayCaseId) ?></div>
                        <div class="vc-hero-meta">
                            <span class="vc-badge" style="background:<?= $statusColor ?>18; color:<?= $statusColor ?>; border:1px solid <?= $statusColor ?>35;"><span class="vc-badge-dot" style="background:<?= $statusColor ?>;"></span><?= htmlspecialchars($status) ?></span>
                            <span class="vc-chip"><i class="uil uil-tag-alt"></i> <?= htmlspecialchars((string)($case['case_type'] ?? 'N/A')) ?></span>
                            <span class="vc-chip"><i class="uil uil-calendar-alt"></i> Filed <?= htmlspecialchars($dateDisplay) ?></span>
                        </div>
                    </div>
                </div>
                <div class="vc-actions">
                    <a href="case_process.php?id=<?= urlencode($caseIdParam) ?>" class="vc-update-btn vc-primary"><i class="uil uil-balance-scale"></i> Process in KP</a>
                    <?php if ($canEditCase): ?>
                    <a href="update_case.php?id=<?= urlencode($caseIdParam) ?><?= $fromProcess ? '&amp;from=process' : '' ?>" class="vc-update-btn"><i class="uil uil-edit"></i> Edit Case</a>
                    <?php endif; ?>
                    <a href="<?= $backUrl ?>" class="vc-back-btn"><i class="uil uil-arrow-left"></i> <?= $backLabel ?></a>
                </div>
            </div>

            <!-- Parties -->
            <div class="vc-parties">
                <div class="card-box vc-party">
                    <span class="vc-avatar"><?= htmlspecialchars($initial($residentName)) ?></span>
                    <div>
                        <label>Resident Name</label>
                        <div class="vc-party-name"><?= htmlspecialchars($residentName) ?></div>
                        <span class="vc-party-role">Complainant</span>
                    </div>
                </div>
                <div class="vc-versus">vs.</div>
                <div class="card-box vc-party">
                    <span class="vc-avatar is-respondent"><?= htmlspecialchars($initial($respondentName)) ?></span>
                    <div>
                        <label>Person Being Complained</label>
                        <div class="vc-party-name"><?= htmlspecialchars($respondentName) ?></div>
                        <span class="vc-party-role">Respondent</span>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="card-box vc-card">
                <div class="vc-card-title"><i class="uil uil-file-alt"></i> Description</div>
                <div class="vc-description"><?= nl2br(htmlspecialchars((string)($case['case_description'] ?? 'No description available.'))) ?></div>
            </div>

            <!-- Evidence -->
            <div class="card-box vc-card">
                <div class="vc-evidence-header">
                    <div>
                        <div class="vc-card-title"><i class="uil uil-paperclip"></i> Submitted Evidence</div>
                        <p class="vc-card-sub">Files, photos, or video recordings uploaded with this complaint</p>
                    </div>
                    <span class="vc-evidence-badge"><i class="uil uil-paperclip"></i> <?= count($evidence) ?> file<?= count($evidence) === 1 ? '' : 's' ?></span>
                </div>
                <?php if (empty($evidence)): ?>
                    <div class="vc-evidence-empty">
                        <i class="uil uil-file-slash"></i>
                        <span>No evidence uploaded for this case.</span>
                    </div>
                <?php else: ?>
                    <div class="vc-evidence-grid">
                        <?php foreach ($evidence as $item): ?>
                            <?php
                            $isVideo = strtolower((string)$item['evidence_type']) === 'video';
                            $sizeMb = number_format(((int)$item['file_size']) / (1024 * 1024), 2);
                            ?>
                            <div class="vc-evidence-card">
                                <div class="vc-evidence-icon <?= $isVideo ? 'is-video' : 'is-image' ?>">
                                    <i class="uil <?= $isVideo ? 'uil-video' : 'uil-image' ?>"></i>
                                </div>
                                <div class="vc-evidence-meta">
                                    <span class="vc-evidence-name" title="<?= htmlspecialchars((string)$item['original_filename']) ?>"><?= htmlspecialchars((string)$item['original_filename']) ?></span>
                                    <span class="vc-evidence-sub"><?= $isVideo ? 'Video Evidence' : 'Photo Evidence' ?> &bull; <?= $sizeMb ?> MB</span>
                                </div>
                                <a href="../api/v1/case_evidence.php?id=<?= (int)$item['id'] ?>" target="_blank" rel="noopener" class="vc-evidence-view-btn">
                                    <i class="uil uil-eye"></i> View
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<style>
.vc-body { padding: 24px 28px; }
.vc-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; }
.vc-sub { font-size: 13px; color: #6b7280; margin: 0; }
.vc-back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 600; text-decoration: none;
    color: #1a3a6b; background: #eef3fb; border: 1px solid #d6e2f5;
    border-radius: 10px; padding: 9px 14px;
}
.vc-back-btn:hover { background: #dfe8f7; }
.vc-update-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 700; text-decoration: none;
    color: #1a3a6b; background: #fff; border: 1.5px solid #c9d6ea;
    border-radius: 10px; padding: 9px 14px;
}
.vc-update-btn:hover { border-color: #1a3a6b; background: #f6f9ff; }
.vc-update-btn.vc-primary { color: #fff; border-color: transparent; background: linear-gradient(135deg, #24508f, #0e2242); box-shadow: 0 8px 18px rgba(26, 58, 107, .22); }
.vc-update-btn.vc-primary:hover { filter: brightness(1.08); }

.vc-hero { display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap; margin-bottom: 16px; padding: 22px 24px; border-left: 4px solid #1a3a6b !important; }
.vc-hero-main { display: flex; align-items: center; gap: 16px; min-width: 0; }
.vc-hero-icon { width: 52px; height: 52px; border-radius: 14px; display: grid; place-items: center; background: #eef3fb; color: #1a3a6b; font-size: 24px; flex-shrink: 0; }
.vc-hero-label { font-size: 11.5px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase; color: #6f7f96; }
.vc-hero-id { font-family: 'Source Serif 4', serif; font-size: 28px; font-weight: 700; color: #0b1a33; line-height: 1.15; }
.vc-hero-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
.vc-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 999px; background: #f1f5f9; color: #334155; font-size: 12.5px; font-weight: 600; }
.vc-chip i { color: #64748b; }
.vc-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.vc-parties { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 12px; margin-bottom: 16px; }
.vc-party { display: flex; align-items: center; gap: 14px; padding: 18px 20px; }
.vc-party label, .vc-hero-label { display: block; }
.vc-party label { font-size: 11.5px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: #6f7f96; margin-bottom: 2px; }
.vc-party-name { font-size: 17px; font-weight: 700; color: #14213a; }
.vc-party-role { display: inline-block; margin-top: 4px; font-size: 12px; font-weight: 600; color: #4f5f75; background: #f1f5f9; border-radius: 999px; padding: 2px 9px; }
.vc-avatar { width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0; background: #e0e7ff; color: #3730a3; font-size: 18px; font-weight: 700; }
.vc-avatar.is-respondent { background: #fff7ed; color: #c2410c; }
.vc-versus { font-family: 'Source Serif 4', serif; font-size: 16px; font-weight: 700; color: #94a3b8; }

.vc-card { padding: 20px 24px; margin-bottom: 16px; }
.vc-card-title { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 700; color: #14213a; }
.vc-card-title i { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; background: #eef3fb; color: #1a3a6b; font-size: 16px; }
.vc-card-sub { margin: 4px 0 0 38px; font-size: 12.5px; color: #6f7f96; }
.vc-description { margin-top: 12px; padding: 14px 16px; border-radius: 12px; background: #f8fafc; border: 1px solid #edf1f7; font-size: 14.5px; line-height: 1.75; color: #1f2937; }

.vc-evidence-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 12px; }
.vc-evidence-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: #f1f5f9; color: #475569; }
.vc-evidence-empty { display: flex; align-items: center; gap: 10px; padding: 16px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; color: #64748b; font-size: 13.5px; }
.vc-evidence-empty i { font-size: 20px; color: #94a3b8; }
.vc-evidence-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.vc-evidence-card { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; transition: border-color .15s, box-shadow .15s; }
.vc-evidence-card:hover { border-color: #93c5fd; box-shadow: 0 6px 16px rgba(26, 58, 107, .08); }
.vc-evidence-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.vc-evidence-icon.is-image { background: #eef3fb; color: #1a3a6b; }
.vc-evidence-icon.is-video { background: #faf5ff; color: #9333ea; }
.vc-evidence-meta { flex: 1; min-width: 0; }
.vc-evidence-name { display: block; font-size: 13px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vc-evidence-sub { display: block; font-size: 12px; color: #64748b; margin-top: 2px; }
.vc-evidence-view-btn { display: inline-flex; align-items: center; gap: 4px; padding: 7px 12px; background: #fff; border: 1px solid #c9d6ea; border-radius: 9px; color: #1a3a6b; font-size: 12.5px; font-weight: 700; text-decoration: none; white-space: nowrap; transition: background .15s, color .15s; }
.vc-evidence-view-btn:hover { background: #1a3a6b; color: #fff; border-color: #1a3a6b; }

.vc-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 11px; border-radius: 999px; white-space: nowrap; }
.vc-badge-dot { width: 6px; height: 6px; border-radius: 50%; }

.vc-empty { text-align: center; padding: 28px 12px; }
.vc-empty i { font-size: 34px; color: #94a3b8; margin-bottom: 8px; display: block; }
.vc-empty-title { font-size: 14px; color: #1f2937; margin-bottom: 10px; }
.vc-back-link { font-size: 12.5px; color: #1d4ed8; text-decoration: none; font-weight: 600; }

@media (max-width: 900px) {
    .vc-body { padding: 16px; }
    .vc-head { flex-direction: column; align-items: flex-start; }
    .vc-parties { grid-template-columns: 1fr; }
    .vc-versus { text-align: center; }
}
</style>
