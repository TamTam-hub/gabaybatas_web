<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/archive.php';
require_once '../includes/barangay_auth.php';
require_once '../includes/flexible_report.php';
require_once '../includes/pagination.php';
gabaybatas_archive_ensure_schema($conn);

// ── Restore (POST), then reload so a refresh does not repeat it ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entity = (string)($_POST['entity'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $flash = ['type' => 'error', 'message' => 'Invalid archive action.'];

    if ($id > 0 && ($_POST['action'] ?? '') === 'restore' && $entity === 'case') {
        $stmt = $conn->prepare('UPDATE cases SET archived_at = NULL, archived_by = NULL, archive_reason = NULL WHERE id = ? AND archived_at IS NOT NULL');
        $stmt->bind_param('i', $id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            gabaybatas_archive_audit($conn, 'case_restored', 'case', (string)$id);
            $flash = ['type' => 'success', 'message' => 'Case restored to Manage Cases.'];
        } else {
            $flash['message'] = 'Case was not restored. It may no longer be archived.';
        }
        $stmt->close();
    } elseif ($id > 0 && ($_POST['action'] ?? '') === 'restore' && $entity === 'user') {
        $stmt = $conn->prepare(
            "UPDATE users
             SET status = COALESCE(NULLIF(archive_previous_status, ''), 'Inactive'),
                 archive_previous_status = NULL, archived_at = NULL, archived_by = NULL, archive_reason = NULL
             WHERE id = ? AND archived_at IS NOT NULL"
        );
        $stmt->bind_param('i', $id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            gabaybatas_archive_audit($conn, 'user_restored', 'user', (string)$id);
            $flash = ['type' => 'success', 'message' => 'User restored to Manage Users.'];
        } else {
            $flash['message'] = 'User was not restored. It may no longer be archived.';
        }
        $stmt->close();
    }

    $_SESSION['archive_flash'] = $flash;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'archive.php'));
    exit();
}
$flash = $_SESSION['archive_flash'] ?? null;
unset($_SESSION['archive_flash']);

$tab = ($_GET['tab'] ?? '') === 'users' ? 'users' : 'cases';
$search = trim((string)($_GET['search'] ?? ''));
$archivedWithin = (string)($_GET['archived'] ?? '');
$archivedOptions = ['' => 'Any time', '30' => 'Last 30 days', '90' => 'Last 90 days', 'year' => 'This year'];
if (!isset($archivedOptions[$archivedWithin])) $archivedWithin = '';
$sort = (string)($_GET['sort'] ?? 'archived_desc');
$pageNo = max(1, (int)($_GET['page'] ?? 1));
$perPage = gabaybatas_per_page(10);

$archivedSql = [
    ''     => '',
    '30'   => ' AND x.archived_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
    '90'   => ' AND x.archived_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)',
    'year' => ' AND YEAR(x.archived_at) = YEAR(NOW())',
][$archivedWithin];

$caseTotal = (int)$conn->query('SELECT COUNT(*) AS t FROM cases WHERE archived_at IS NOT NULL')->fetch_assoc()['t'];
$userTotal = (int)$conn->query('SELECT COUNT(*) AS t FROM users WHERE archived_at IS NOT NULL')->fetch_assoc()['t'];

$where = 'x.archived_at IS NOT NULL' . $archivedSql;
$like = $search !== '' ? '%' . $conn->real_escape_string($search) . '%' : '';
$quoteList = static fn(array $keys): string => implode(',', array_map(static fn($k) => "'" . $conn->real_escape_string($k) . "'", $keys));

if ($tab === 'cases') {
    $groups = gabaybatas_flex_groups();
    $kpStatusSql = "COALESCE(NULLIF(x.kp_status, ''), 'filed')";
    $status = trim((string)($_GET['status'] ?? ''));
    $isGroup = str_starts_with($status, 'group:') && isset($groups[substr($status, 6)]);
    if (!$isGroup && !array_key_exists($status, kp_statuses())) $status = '';
    $caseType = trim((string)($_GET['case_type'] ?? ''));
    $caseTypes = gabaybatas_flex_case_types($conn, '');
    if (!in_array($caseType, $caseTypes, true)) $caseType = '';
    $sorts = ['archived_desc' => 'Recently archived', 'archived_asc' => 'Oldest archived', 'filed_desc' => 'Recently filed', 'case_asc' => 'Case no. (ascending)'];

    if ($like !== '') {
        $where .= " AND (x.case_id LIKE '$like' OR x.name LIKE '$like' OR x.complained_person LIKE '$like' OR x.archive_reason LIKE '$like' OR CONCAT('C-', LPAD(x.id, 4, '0')) LIKE '$like')";
    }
    if ($isGroup) {
        $where .= " AND $kpStatusSql IN (" . $quoteList($groups[substr($status, 6)]['statuses']) . ')';
    } elseif ($status !== '') {
        $where .= " AND $kpStatusSql = '" . $conn->real_escape_string($status) . "'";
    }
    if ($caseType !== '') {
        $where .= " AND x.case_type = '" . $conn->real_escape_string($caseType) . "'";
    }

    $statusesByStage = [];
    foreach (kp_statuses() as $key => $info) {
        $statusesByStage[$info['stage']][$key] = $info['label'];
    }
    $stages = kp_stages();
    $filters = ['tab' => 'cases', 'search' => $search, 'status' => $status, 'case_type' => $caseType, 'archived' => $archivedWithin, 'sort' => $sort];
} else {
    $role = strtolower(trim((string)($_GET['role'] ?? '')));
    $roleLabels = ['resident' => 'Resident', 'barangay' => 'Barangay Official', 'admin' => 'Administrator'];
    if (!isset($roleLabels[$role])) $role = '';
    $sorts = ['archived_desc' => 'Recently archived', 'archived_asc' => 'Oldest archived', 'name_asc' => 'Name A–Z', 'name_desc' => 'Name Z–A'];

    if ($like !== '') {
        $where .= " AND (x.name LIKE '$like' OR x.email LIKE '$like' OR x.username LIKE '$like' OR x.archive_reason LIKE '$like')";
    }
    if ($role !== '') {
        $where .= " AND x.role = '$role'";
    }
    $filters = ['tab' => 'users', 'search' => $search, 'role' => $role, 'archived' => $archivedWithin, 'sort' => $sort];
}
if (!isset($sorts[$sort])) {
    $sort = 'archived_desc';
    $filters['sort'] = $sort;
}

$table = $tab === 'cases' ? 'cases' : 'users';
$filtered = (int)$conn->query("SELECT COUNT(*) AS t FROM $table x WHERE $where")->fetch_assoc()['t'];
$pages = max(1, (int)ceil($filtered / $perPage));
$pageNo = min($pageNo, $pages);
$offset = ($pageNo - 1) * $perPage;
$orderBy = [
    'archived_desc' => 'x.archived_at DESC',
    'archived_asc'  => 'x.archived_at ASC',
    'filed_desc'    => 'x.date_submitted DESC',
    'case_asc'      => 'x.id ASC',
    'name_asc'      => 'x.name ASC',
    'name_desc'     => 'x.name DESC',
][$sort];

$rows = $tab === 'cases'
    ? $conn->query(
        "SELECT x.id, x.case_id, x.name, x.complained_person, x.case_type, $kpStatusSql AS kp_status,
                x.date_submitted, x.archived_at, x.archive_reason, x.legal_hold, a.name AS archived_by_name
         FROM cases x LEFT JOIN users a ON a.id = x.archived_by
         WHERE $where ORDER BY $orderBy LIMIT $offset, $perPage"
    )->fetch_all(MYSQLI_ASSOC)
    : $conn->query(
        "SELECT x.id, x.name, x.email, x.username, x.role, x.archive_previous_status, x.created_at,
                x.archived_at, x.archive_reason, x.legal_hold, a.name AS archived_by_name
         FROM users x LEFT JOIN users a ON a.id = x.archived_by
         WHERE $where ORDER BY $orderBy LIMIT $offset, $perPage"
    )->fetch_all(MYSQLI_ASSOC);

$hasFilters = $search !== '' || $archivedWithin !== '' || ($tab === 'cases' ? ($status !== '' || $caseType !== '') : $role !== '');
$dateCell = static function ($value): string {
    $ts = strtotime((string)$value);
    return $ts ? '<strong>' . date('M d, Y', $ts) . '</strong><small>' . date('h:i A', $ts) . '</small>' : '—';
};
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left"><div class="topbar-title">Records Archive</div></div>
        <div class="topbar-right">
            <div class="notif-btn"><i class="uil uil-bell"></i></div>
            <div class="topbar-sep"></div>
            <div class="user-pill"><div class="user-avatar"><i class="uil uil-user"></i></div><div class="user-text"><strong><?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div>
        </div>
    </div>

    <div class="page-body ar-body">
        <p class="ar-intro">Archived records are kept for official and legal reference. Restoring a record returns it to the active lists; the archive history stays in the audit log.</p>

        <?php if ($flash): ?>
        <div class="ar-alert <?= $flash['type'] === 'success' ? 'is-success' : 'is-error' ?>"><i class="uil <?= $flash['type'] === 'success' ? 'uil-check-circle' : 'uil-exclamation-circle' ?>"></i><?= htmlspecialchars($flash['message']) ?></div>
        <?php endif; ?>

        <nav class="ar-tabs" aria-label="Archive sections">
            <a href="?tab=cases" class="ar-tab <?= $tab === 'cases' ? 'active' : '' ?>"><i class="uil uil-folder-open"></i> Archived Cases <span><?= $caseTotal ?></span></a>
            <a href="?tab=users" class="ar-tab <?= $tab === 'users' ? 'active' : '' ?>"><i class="uil uil-users-alt"></i> Archived Users <span><?= $userTotal ?></span></a>
        </nav>

        <section class="ar-card">
            <form method="GET" class="ar-toolbar">
                <input type="hidden" name="tab" value="<?= $tab ?>">
                <label class="ar-search"><i class="uil uil-search"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="<?= $tab === 'cases' ? 'Search case no., complainant, respondent, or reason' : 'Search name, email, username, or reason' ?>">
                </label>
                <?php if ($tab === 'cases'): ?>
                <select name="status" onchange="this.form.submit()" aria-label="Status">
                    <option value="">All statuses</option>
                    <optgroup label="Status groups">
                        <?php foreach ($groups as $gKey => $g): ?>
                        <option value="group:<?= $gKey ?>" <?= $status === 'group:' . $gKey ? 'selected' : '' ?>><?= htmlspecialchars($g['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php foreach ($statusesByStage as $stage => $statusList): ?>
                    <optgroup label="<?= htmlspecialchars($stages[$stage]['label'] ?? ucfirst($stage)) ?>">
                        <?php foreach ($statusList as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <select name="case_type" onchange="this.form.submit()" aria-label="Case type">
                    <option value="">All case types</option>
                    <?php foreach ($caseTypes as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $caseType === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <select name="role" onchange="this.form.submit()" aria-label="Role">
                    <option value="">All roles</option>
                    <?php foreach ($roleLabels as $rKey => $rLabel): ?>
                    <option value="<?= $rKey ?>" <?= $role === $rKey ? 'selected' : '' ?>><?= $rLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select name="archived" onchange="this.form.submit()" aria-label="Archived">
                    <?php foreach ($archivedOptions as $aKey => $aLabel): ?>
                    <option value="<?= $aKey ?>" <?= $archivedWithin === (string)$aKey ? 'selected' : '' ?>>Archived: <?= $aLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="sort" onchange="this.form.submit()" aria-label="Sort">
                    <?php foreach ($sorts as $sKey => $sLabel): ?>
                    <option value="<?= $sKey ?>" <?= $sort === $sKey ? 'selected' : '' ?>><?= $sLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="per_page" value="<?= $perPage ?>">
                <button type="submit" class="ar-btn primary"><i class="uil uil-search"></i> Search</button>
                <?php if ($hasFilters): ?><a href="?tab=<?= $tab ?>" class="ar-btn"><i class="uil uil-times"></i> Clear</a><?php endif; ?>
            </form>

            <div class="ar-table-wrap">
                <table class="ar-table">
                    <?php if ($tab === 'cases'): ?>
                    <thead><tr><th>Case No.</th><th>Complainant / Respondent</th><th>Case Type</th><th>Status when archived</th><th>Archived</th><th>Reason</th><th class="ar-actions-col">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): $color = kp_status_color($r['kp_status']); ?>
                        <tr>
                            <td><span class="ar-id"><?= htmlspecialchars(gabaybatas_format_case_id($r['case_id'] ?? '', $r['id'])) ?></span><?php if ((int)$r['legal_hold'] === 1): ?><span class="ar-hold" title="On legal hold">Legal hold</span><?php endif; ?></td>
                            <td><strong><?= htmlspecialchars((string)($r['name'] ?: '—')) ?></strong><small>vs. <?= htmlspecialchars((string)($r['complained_person'] ?: '—')) ?></small></td>
                            <td class="ar-muted"><?= htmlspecialchars((string)($r['case_type'] ?: '—')) ?></td>
                            <td><span class="ar-badge" style="background:<?= $color ?>18;color:<?= $color ?>;border-color:<?= $color ?>44;"><span></span><?= htmlspecialchars(kp_status_label($r['kp_status'])) ?></span></td>
                            <td class="ar-date"><?= $dateCell($r['archived_at']) ?><?php if (!empty($r['archived_by_name'])): ?><small>by <?= htmlspecialchars((string)$r['archived_by_name']) ?></small><?php endif; ?></td>
                            <td><div class="ar-reason" title="<?= htmlspecialchars((string)$r['archive_reason']) ?>"><?= htmlspecialchars((string)($r['archive_reason'] ?: '—')) ?></div></td>
                            <td class="ar-actions">
                                <a href="view_case.php?id=<?= (int)$r['id'] ?>" class="ar-act view" title="View case"><i class="uil uil-eye"></i> View</a>
                                <form method="POST" class="ar-restore-form"><input type="hidden" name="entity" value="case"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="restore"><button class="ar-act restore" type="submit"><i class="uil uil-redo"></i> Restore</button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php else: ?>
                    <thead><tr><th>User</th><th>Role</th><th>Status before archive</th><th>Registered</th><th>Archived</th><th>Reason</th><th class="ar-actions-col">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><div class="ar-person"><span class="ar-avatar"><?= htmlspecialchars(strtoupper(substr((string)$r['name'], 0, 1)) ?: '?') ?></span><span><strong><?= htmlspecialchars((string)$r['name']) ?></strong><small><?= htmlspecialchars((string)($r['email'] ?: $r['username'] ?: '—')) ?></small></span></div></td>
                            <td class="ar-muted"><?= htmlspecialchars($roleLabels[strtolower((string)$r['role'])] ?? ucfirst((string)$r['role'])) ?></td>
                            <td class="ar-muted"><?= htmlspecialchars(ucfirst(strtolower((string)($r['archive_previous_status'] ?: '—')))) ?></td>
                            <td class="ar-date"><?= $dateCell($r['created_at']) ?></td>
                            <td class="ar-date"><?= $dateCell($r['archived_at']) ?><?php if (!empty($r['archived_by_name'])): ?><small>by <?= htmlspecialchars((string)$r['archived_by_name']) ?></small><?php endif; ?></td>
                            <td><div class="ar-reason" title="<?= htmlspecialchars((string)$r['archive_reason']) ?>"><?= htmlspecialchars((string)($r['archive_reason'] ?: '—')) ?></div></td>
                            <td class="ar-actions">
                                <form method="POST" class="ar-restore-form"><input type="hidden" name="entity" value="user"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="restore"><button class="ar-act restore" type="submit"><i class="uil uil-redo"></i> Restore</button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>
                    <?php if (!$rows): ?>
                    <tbody><tr><td colspan="7"><div class="ar-empty"><i class="uil uil-archive"></i><strong><?= $hasFilters ? 'No archived ' . $tab . ' match these filters' : 'No archived ' . $tab . ' yet' ?></strong><span><?= $hasFilters ? 'Try a different search or clear the filters.' : 'Records you archive will appear here.' ?></span></div></td></tr></tbody>
                    <?php endif; ?>
                </table>
            </div>

            <?php gabaybatas_pagination($filtered, $pageNo, $perPage, $filters, $tab === 'cases' ? 'archived cases' : 'archived users'); ?>
        </section>
    </div>
</div>

<script>
document.querySelectorAll('.ar-restore-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (window.AdminConfirm) {
            const confirmed = await window.AdminConfirm.confirm({
                title: 'Restore this record?',
                message: 'The record will return to the active system views. Its archive history will remain in the audit log.',
                confirmText: 'Restore Record',
                cancelText: 'Cancel'
            });
            if (!confirmed) return;
        }
        form.submit();
    });
});
</script>

<style>
.ar-body{padding:24px 28px 44px;}
.ar-intro{margin:0 0 16px;color:#64748b;font-size:13px;}
.ar-alert{display:flex;align-items:center;gap:8px;padding:11px 14px;border-radius:9px;margin-bottom:14px;font-size:13px;font-weight:600;}
.ar-alert.is-success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;}
.ar-alert.is-error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3;}
.ar-tabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;}
.ar-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:11px;font-size:13.5px;font-weight:600;color:#475569;text-decoration:none;background:#fff;border:1.5px solid #e9eef5;transition:all .2s;}
.ar-tab:hover{border-color:#cbd5e1;color:#1e293b;}
.ar-tab span{font-size:12px;font-weight:700;padding:2px 8px;border-radius:100px;background:#f1f5f9;color:#64748b;}
.ar-tab.active{background:#3b82f6;border-color:#3b82f6;color:#fff;box-shadow:0 4px 12px rgba(59,130,246,.28);}
.ar-tab.active span{background:rgba(255,255,255,.28);color:#fff;}
.ar-card{background:#fff;border:1px solid #e5eaf1;border-radius:16px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.ar-toolbar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid #eef2f7;}
.ar-search{position:relative;flex:1 1 280px;min-width:220px;}
.ar-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:15px;}
.ar-search input,.ar-toolbar select{height:38px;width:100%;border:1px solid #e2e8f0;border-radius:9px;background:#fff;color:#334155;font-size:12.5px;padding:0 10px;font-family:inherit;box-sizing:border-box;}
.ar-search input{padding-left:34px;background:#fafbff;}
.ar-search input:focus,.ar-toolbar select:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.ar-toolbar select{width:auto;max-width:210px;cursor:pointer;}
.ar-btn{height:38px;border-radius:9px;padding:0 13px;font-size:12.5px;font-weight:600;display:inline-flex;align-items:center;gap:5px;text-decoration:none;border:1px solid #dbe3ef;background:#fff;color:#475569;cursor:pointer;font-family:inherit;}
.ar-btn.primary{background:#1d4ed8;border-color:#1d4ed8;color:#fff;}
.ar-table-wrap{overflow-x:auto;}
.ar-table{width:100%;border-collapse:collapse;font-size:13px;min-width:980px;}
.ar-table th{padding:13px 16px;text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.7px;color:#64748b;font-weight:700;background:#fafbff;border-bottom:2px solid #e5e7eb;white-space:nowrap;}
.ar-table td{padding:13px 16px;border-bottom:1px solid #f3f4f6;vertical-align:middle;color:#334155;}
.ar-table tbody tr:hover td{background:#f8fafc;}
.ar-table td strong{display:block;color:#1e293b;font-weight:600;}
.ar-table td small{display:block;font-size:11.5px;color:#94a3b8;margin-top:2px;}
.ar-muted{color:#64748b;}
.ar-id{font-family:monospace;font-size:11.5px;font-weight:600;background:#f1f5f9;color:#475569;padding:4px 9px;border-radius:6px;white-space:nowrap;}
.ar-hold{display:block;width:max-content;margin-top:5px;font-size:10px;font-weight:700;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:999px;padding:1px 7px;}
.ar-badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;line-height:1.35;padding:4px 11px;border-radius:999px;border:1px solid transparent;}
.ar-badge span{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.ar-date strong{font-size:12.5px;white-space:nowrap;}
.ar-reason{max-width:240px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;color:#475569;font-size:12.5px;line-height:1.45;}
.ar-person{display:flex;align-items:center;gap:10px;}
.ar-avatar{width:32px;height:32px;border-radius:50%;background:#e0e7ff;color:#4f46e5;display:grid;place-items:center;font-size:13px;font-weight:700;flex-shrink:0;}
.ar-actions-col{text-align:right!important;}
.ar-actions{white-space:nowrap;text-align:right;}
.ar-actions form{display:inline;}
.ar-act{display:inline-flex;align-items:center;gap:4px;border-radius:8px;padding:6px 10px;font-size:12px;font-weight:600;text-decoration:none;cursor:pointer;font-family:inherit;margin-left:4px;}
.ar-act.view{border:1px solid #dbeafe;background:#eff6ff;color:#1d4ed8;}
.ar-act.restore{border:1px solid #bbf7d0;background:#f0fdf4;color:#15803d;}
.ar-act:hover{filter:brightness(.96);}
.ar-empty{display:flex;flex-direction:column;align-items:center;gap:5px;padding:48px 20px;color:#64748b;}
.ar-empty i{font-size:32px;color:#cbd5e1;}
.ar-empty strong{color:#334155;font-size:14px;}
.ar-empty span{font-size:12.5px;}
@media(max-width:760px){.ar-body{padding:16px 12px 30px;}.ar-toolbar{padding:12px;}.ar-toolbar select,.ar-btn{flex:1 1 45%;max-width:none;}}
</style>
</body>
</html>
