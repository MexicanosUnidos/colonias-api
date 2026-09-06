<?php
/**
 * GET /buscar — búsqueda por texto o código postal.
 * Si "q" son 5 dígitos, busca por código postal exacto.
 * Si no, hace FULLTEXT sobre nombre de colonia + municipio/estado.
 */

$q = trim($_GET['q'] ?? '');
$estadoId = $_GET['estado_id'] ?? null;
$municipioId = $_GET['municipio_id'] ?? null;
$limit = $_GET['limit'] ?? 10;

$longitudQ = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
if ($longitudQ < 2) {
    jsonResponse(false, 'El texto debe tener al menos 2 caracteres', 400);
}

$limit = max(1, min(50, (int) $limit));

$cacheKey = 'buscar_' . $q . '_' . ($estadoId ?? '') . '_' . ($municipioId ?? '') . '_' . $limit;
$cached = cacheGet($cacheKey);
if ($cached !== null) {
    jsonResponse(true, $cached, 200, $startTime);
}

$db = getDB();

$where = [];
$params = [];

if ($estadoId !== null && ctype_digit((string) $estadoId)) {
    $where[] = 'e.id = ?';
    $params[] = (int) $estadoId;
}

if ($municipioId !== null && ctype_digit((string) $municipioId)) {
    $where[] = 'm.id = ?';
    $params[] = (int) $municipioId;
}

$baseSelect = 'SELECT
        c.id, c.nombre, c.tipo, c.codigo_postal,
        m.id AS municipio_id, m.nombre AS municipio,
        e.id AS estado_id, e.nombre AS estado
    FROM colonias c
    JOIN municipios m ON m.id = c.municipio_id
    JOIN estados e ON e.id = m.estado_id';

if (ctype_digit($q) && strlen($q) === 5) {
    $where[] = 'c.codigo_postal = ?';
    $params[] = $q;
    $sql = "$baseSelect WHERE " . implode(' AND ', $where) . ' ORDER BY c.nombre LIMIT ' . $limit;
} else {
    $where[] = 'MATCH(c.nombre) AGAINST (? IN NATURAL LANGUAGE MODE)';
    $params[] = $q;
    $sql = "$baseSelect WHERE " . implode(' AND ', $where) . ' LIMIT ' . $limit;
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$resultados = castIds($stmt->fetchAll(), ['id', 'municipio_id', 'estado_id']);

cacheSet($cacheKey, $resultados);

jsonResponse(true, $resultados, 200, $startTime);
