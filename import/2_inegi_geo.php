<?php
/**
 * Importa los polígonos del Marco Geoestadístico de INEGI.
 * Lee cada GeoJSON en import/data/inegi/*.geojson, normaliza el nombre
 * de la colonia (sin acentos, mayúsculas) y busca match en `colonias`
 * por nombre + clave de municipio. Los que emparejan se insertan en
 * `colonia_poligonos` y actualizan el centroide real de la colonia.
 * Los que no emparejan se registran en import/logs/sin_match.txt.
 *
 * Uso: php import/2_inegi_geo.php
 */

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$geoDir = __DIR__ . '/data/inegi';
$archivos = glob($geoDir . '/*.geojson');

if (empty($archivos)) {
    fwrite(STDERR, "No se encontraron archivos .geojson en $geoDir (ver M4.1).\n");
    exit(1);
}

function normalizarNombre(string $nombre): string
{
    $nombre = function_exists('mb_strtoupper') ? mb_strtoupper(trim($nombre), 'UTF-8') : strtoupper(trim($nombre));
    $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre;
    $nombre = preg_replace('/[^A-Z0-9 ]/', '', $nombre);
    return preg_replace('/\s+/', ' ', $nombre);
}

$db = getDB();

// Índice colonia por (clave_municipio, nombre normalizado) -> id
$colonias = $db->query(
    'SELECT c.id, c.nombre, m.clave_inegi FROM colonias c JOIN municipios m ON m.id = c.municipio_id'
)->fetchAll();

$indice = [];
foreach ($colonias as $c) {
    $clave = $c['clave_inegi'] . '|' . normalizarNombre($c['nombre']);
    $indice[$clave] = (int) $c['id'];
}

$insertPoligono = $db->prepare(
    'INSERT INTO colonia_poligonos (colonia_id, poligono) VALUES (?, ST_GeomFromGeoJSON(?))
     ON DUPLICATE KEY UPDATE poligono = VALUES(poligono)'
);
$updateCentroide = $db->prepare(
    'UPDATE colonias SET centroide = ST_Centroid(ST_GeomFromGeoJSON(?)) WHERE id = ?'
);

$logPath = __DIR__ . '/logs/sin_match.txt';
$logHandle = fopen($logPath, 'w');

$totalFeatures = 0;
$conMatch = 0;

foreach ($archivos as $archivo) {
    $geojson = json_decode(file_get_contents($archivo), true);
    if (!isset($geojson['features'])) {
        continue;
    }

    foreach ($geojson['features'] as $feature) {
        $totalFeatures++;
        $props = $feature['properties'] ?? [];
        $nombreGeo = $props['NOMGEO'] ?? '';
        $claveMun = ($props['CVE_ENT'] ?? '') . ($props['CVE_MUN'] ?? '');

        $clave = $claveMun . '|' . normalizarNombre($nombreGeo);

        if (!isset($indice[$clave])) {
            fwrite($logHandle, "$archivo\t$nombreGeo\t$claveMun\n");
            continue;
        }

        $coloniaId = $indice[$clave];
        $geometryJson = json_encode($feature['geometry']);

        $insertPoligono->execute([$coloniaId, $geometryJson]);
        $updateCentroide->execute([$geometryJson, $coloniaId]);
        $conMatch++;
    }
}

fclose($logHandle);

$sinMatch = $totalFeatures - $conMatch;
$porcentaje = $totalFeatures > 0 ? round($conMatch * 100 / $totalFeatures, 1) : 0;

echo "Features procesadas: $totalFeatures\n";
echo "Con match (polígono insertado): $conMatch\n";
echo "Sin match (ver $logPath): $sinMatch\n";
echo "Cobertura: $porcentaje%\n";
