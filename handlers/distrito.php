<?php
/**
 * GET /distrito?seccion=&estado_id= — distrito federal y local de una
 * sección electoral. Búsqueda directa contra el catálogo del INE
 * (secciones_electorales), sin geocodificación ni cálculo geográfico.
 */

$seccion = $_GET['seccion'] ?? null;
$estadoId = $_GET['estado_id'] ?? null;

if ($seccion === null || trim((string) $seccion) === '') {
    jsonResponse(false, 'El parámetro "seccion" es requerido', 400);
}

if ($estadoId === null || !ctype_digit((string) $estadoId)) {
    jsonResponse(false, 'El parámetro "estado_id" es requerido y debe ser numérico', 400);
}

// M9.2: normalizar a 4 dígitos con ceros a la izquierda (?seccion=1 == ?seccion=0001)
$seccion = str_pad(trim((string) $seccion), 4, '0', STR_PAD_LEFT);
$estadoId = (int) $estadoId;

$cacheKey = 'distrito_' . $seccion . '_' . $estadoId;
$cached = cacheGet($cacheKey);
if ($cached !== null) {
    jsonResponse(true, $cached, 200, $startTime);
}

$db = getDB();
$stmt = $db->prepare(
    'SELECT
        se.seccion, se.estado_id, e.nombre AS estado,
        df.numero AS distrito_federal, df.cabecera AS distrito_federal_cabecera,
        dl.numero AS distrito_local, dl.cabecera AS distrito_local_cabecera,
        m.id AS municipio_id, m.nombre AS municipio
    FROM secciones_electorales se
    JOIN estados e ON e.id = se.estado_id
    JOIN distritos_federales df ON df.id = se.distrito_federal_id
    LEFT JOIN distritos_locales dl ON dl.id = se.distrito_local_id
    LEFT JOIN municipios m ON m.id = se.municipio_id
    WHERE se.seccion = ? AND se.estado_id = ?
    LIMIT 1'
);
$stmt->execute([$seccion, $estadoId]);
$distrito = $stmt->fetch();

// M9.4: sección inexistente para ese estado
if (!$distrito) {
    jsonResponse(false, 'Sección electoral no encontrada para ese estado', 404);
}

$distrito['estado_id'] = (int) $distrito['estado_id'];
$distrito['distrito_federal'] = (int) $distrito['distrito_federal'];
$distrito['distrito_local'] = $distrito['distrito_local'] !== null ? (int) $distrito['distrito_local'] : null;
$distrito['municipio_id'] = $distrito['municipio_id'] !== null ? (int) $distrito['municipio_id'] : null;

// M9.8: TTL largo — el catálogo solo cambia si el INE redistritó.
cacheSet($cacheKey, $distrito, 30 * 86400);

jsonResponse(true, $distrito, 200, $startTime);
