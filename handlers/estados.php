<?php
/**
 * GET /estados — catálogo completo de los 32 estados.
 */

$cacheKey = 'estados';
$cached = cacheGet($cacheKey);
if ($cached !== null) {
    jsonResponse(true, $cached, 200, $startTime);
}

$db = getDB();
$stmt = $db->query('SELECT id, clave, nombre FROM estados ORDER BY nombre');
$estados = castIds($stmt->fetchAll(), ['id']);

cacheSet($cacheKey, $estados);

jsonResponse(true, $estados, 200, $startTime);
