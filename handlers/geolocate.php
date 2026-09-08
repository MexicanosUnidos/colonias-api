<?php
/**
 * GET /geolocate?lat=&lng= — colonia exacta por punto GPS.
 * 1. ST_Within(POINT(lng,lat), poligono) — exacto, usa SPATIAL INDEX
 * 2. Si no hay match (sin polígono o en frontera), fallback Haversine
 *    contra las colonias con centroide más cercano.
 */

require_once __DIR__ . '/../helpers/geo.php';

$lat = $_GET['lat'] ?? null;
$lng = $_GET['lng'] ?? null;

if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
    jsonResponse(false, 'Los parámetros "lat" y "lng" son requeridos y deben ser numéricos', 400);
}

$lat = (float) $lat;
$lng = (float) $lng;

// Rango aproximado del territorio mexicano
if ($lat < 14 || $lat > 33 || $lng < -118 || $lng > -86) {
    jsonResponse(false, 'Coordenadas fuera del territorio mexicano', 400);
}

$cacheKey = 'geo_' . round($lat, 3) . '_' . round($lng, 3);
$cached = cacheGet($cacheKey);
if ($cached !== null) {
    jsonResponse(true, $cached, 200, $startTime);
}

$db = getDB();

$stmt = $db->prepare(
    'SELECT
        c.id, c.nombre, c.tipo, c.codigo_postal,
        m.id AS municipio_id, m.nombre AS municipio,
        e.id AS estado_id, e.nombre AS estado,
        ST_Y(c.centroide) AS lat, ST_X(c.centroide) AS lng
    FROM colonia_poligonos cp
    JOIN colonias c ON c.id = cp.colonia_id
    JOIN municipios m ON m.id = c.municipio_id
    JOIN estados e ON e.id = m.estado_id
    WHERE ST_Within(POINT(?, ?), cp.poligono)
    LIMIT 1'
);
$stmt->execute([$lng, $lat]);
$resultado = $stmt->fetch();

if ($resultado) {
    $resultado = castIds($resultado, ['id', 'municipio_id', 'estado_id']);
    $resultado['lat'] = (float) $resultado['lat'];
    $resultado['lng'] = (float) $resultado['lng'];
    $resultado['metodo'] = 'poligono';
} else {
    // Fallback: bounding box aproximado (~0.1 grados ~ 11km) + Haversine
    $delta = 0.1;
    $stmt = $db->prepare(
        'SELECT
            c.id, c.nombre, c.tipo, c.codigo_postal,
            m.id AS municipio_id, m.nombre AS municipio,
            e.id AS estado_id, e.nombre AS estado,
            ST_Y(c.centroide) AS lat, ST_X(c.centroide) AS lng
        FROM colonias c
        JOIN municipios m ON m.id = c.municipio_id
        JOIN estados e ON e.id = m.estado_id
        WHERE c.centroide IS NOT NULL
          AND ST_Y(c.centroide) BETWEEN ? AND ?
          AND ST_X(c.centroide) BETWEEN ? AND ?
        LIMIT 20'
    );
    $stmt->execute([$lat - $delta, $lat + $delta, $lng - $delta, $lng + $delta]);
    $candidatos = $stmt->fetchAll();

    $mejor = null;
    $mejorDistancia = INF;
    foreach ($candidatos as $cand) {
        $d = haversineMetros($lat, $lng, (float) $cand['lat'], (float) $cand['lng']);
        if ($d < $mejorDistancia) {
            $mejorDistancia = $d;
            $mejor = $cand;
        }
    }

    if (!$mejor) {
        jsonResponse(false, 'No se encontró ninguna colonia cerca de esas coordenadas', 404);
    }

    $mejor = castIds($mejor, ['id', 'municipio_id', 'estado_id']);
    $mejor['lat'] = (float) $mejor['lat'];
    $mejor['lng'] = (float) $mejor['lng'];
    $mejor['metodo'] = 'centroide';
    $resultado = $mejor;
}

cacheSet($cacheKey, $resultado);

jsonResponse(true, $resultado, 200, $startTime);
