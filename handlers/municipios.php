<?php
/**
 * GET /municipios?estado_id=9 — municipios de un estado.
 */

$estadoId = $_GET['estado_id'] ?? null;

if ($estadoId === null || !ctype_digit((string) $estadoId)) {
    jsonResponse(false, 'El parámetro "estado_id" es requerido y debe ser numérico', 400);
}

$estadoId = (int) $estadoId;

$db = getDB();
$stmt = $db->prepare(
    'SELECT id, nombre, clave_inegi FROM municipios WHERE estado_id = ? ORDER BY nombre'
);
$stmt->execute([$estadoId]);
$municipios = castIds($stmt->fetchAll(), ['id']);

jsonResponse(true, $municipios, 200, $startTime);
