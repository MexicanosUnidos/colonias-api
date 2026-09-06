<?php
/**
 * GET /colonia/{id} — detalle completo de una colonia.
 */

$id = $_GET['id'] ?? null;

if ($id === null || !ctype_digit((string) $id)) {
    jsonResponse(false, 'ID de colonia inválido', 400);
}

$db = getDB();
$stmt = $db->prepare(
    'SELECT
        c.id, c.nombre, c.tipo, c.codigo_postal,
        m.id AS municipio_id, m.nombre AS municipio,
        e.id AS estado_id, e.nombre AS estado,
        ST_Y(c.centroide) AS lat, ST_X(c.centroide) AS lng,
        (cp.colonia_id IS NOT NULL) AS tiene_poligono
    FROM colonias c
    JOIN municipios m ON m.id = c.municipio_id
    JOIN estados e ON e.id = m.estado_id
    LEFT JOIN colonia_poligonos cp ON cp.colonia_id = c.id
    WHERE c.id = ?
    LIMIT 1'
);
$stmt->execute([(int) $id]);
$colonia = $stmt->fetch();

if (!$colonia) {
    jsonResponse(false, 'Colonia no encontrada', 404);
}

$colonia = castIds($colonia, ['id', 'municipio_id', 'estado_id']);
$colonia['tiene_poligono'] = (bool) $colonia['tiene_poligono'];
$colonia['lat'] = $colonia['lat'] !== null ? (float) $colonia['lat'] : null;
$colonia['lng'] = $colonia['lng'] !== null ? (float) $colonia['lng'] : null;

jsonResponse(true, $colonia, 200, $startTime);
