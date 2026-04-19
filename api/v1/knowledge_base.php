<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('GET');

$columns = api_kb_columns($conn);
if (empty($columns)) {
    api_error('Knowledge base table is not available.', 500);
}

$titleColumn = api_has_column($columns, 'title') ? 'title' : null;
$contentColumn = api_has_column($columns, 'content') ? 'content' : null;
$categoryColumn = api_has_column($columns, 'category') ? 'category' : null;

if ($titleColumn === null || $contentColumn === null) {
    api_error('Knowledge base table is missing required columns.', 500);
}

$search = trim((string)($_GET['search'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));

$whereParts = [];
if ($search !== '') {
    $searchEsc = $conn->real_escape_string($search);
    $whereParts[] = "($titleColumn LIKE '%$searchEsc%' OR $contentColumn LIKE '%$searchEsc%')";
}

if ($category !== '' && $categoryColumn !== null) {
    $categoryEsc = $conn->real_escape_string($category);
    $whereParts[] = "$categoryColumn = '$categoryEsc'";
}

$whereSql = empty($whereParts) ? '' : ('WHERE ' . implode(' AND ', $whereParts));
$catSelect = $categoryColumn ? $categoryColumn : "''";

$sql = "SELECT id, $titleColumn AS title, $contentColumn AS content, $catSelect AS category FROM knowledge_base $whereSql ORDER BY id DESC";
$res = $conn->query($sql);
if (!$res) {
    api_error('Unable to fetch knowledge base items.', 500);
}

$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'title' => (string)($row['title'] ?? ''),
        'content' => (string)($row['content'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
    ];
}

api_success([
    'message' => 'Knowledge base fetched.',
    'count' => count($items),
    'items' => $items,
]);
