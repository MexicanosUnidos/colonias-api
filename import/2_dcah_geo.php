<?php
/**
 * Importa los polígonos de colonias del programa DCAH del INEGI
 * (Delimitación de Colonias y otros Asentamientos Humanos), capa "AS".
 *
 * A diferencia de lo documentado originalmente en el PLAN (que asumía
 * GeoJSON en WGS84), el producto real es un Shapefile (.shp/.dbf) en
 * una proyección Cónica Conforme de Lambert (parámetros confirmados en
 * el .prj: México ITRF2008 LCC, meridiano central -102°, paralelos
 * estándar 17.5°/29.5°, origen 12°N, falso este 2,500,000). Este
 * script reproyecta cada vértice a lat/lng (WGS84) antes de guardarlo.
 *
 * No depende de GDAL/ogr2ogr/SimpleXML — parser de Shapefile y
 * proyección Lambert Conformal Conic escritos a mano, en PHP puro,
 * validados contra un punto real (ver docs/PLAN.md sección 2.2).
 *
 * Uso: php import/2_dcah_geo.php ["ruta/al/00as" (sin extensión)]
 * Por default usa Poligonos/00_integrados/conjunto_de_datos/00as
 * (el archivo NACIONAL integrado — no hace falta procesar los 32
 * estados por separado, ya vienen combinados ahí: 79,775 polígonos).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/_admin_run.php';

permitirSoloCli();

$basePath = $argv[1] ?? __DIR__ . '/../Poligonos/00_integrados/conjunto_de_datos/00as';
$shpPath = $basePath . '.shp';
$dbfPath = $basePath . '.dbf';

if (!is_file($shpPath) || !is_file($dbfPath)) {
    abortarImport("No se encontraron $shpPath / $dbfPath (ver M4.1).");
}

// --- Proyección: Lambert Conformal Conic inversa (Snyder 1987) ---
// Parámetros tomados del archivo .prj (México_ITRF2008_LCC / GRS80).
function lccInversa(float $x, float $y): array
{
    $a = 6378137.0;
    $f = 1 / 298.257222101;
    $e2 = 2 * $f - $f * $f;
    $e = sqrt($e2);

    $lat0 = deg2rad(12.0);
    $lat1 = deg2rad(17.5);
    $lat2 = deg2rad(29.5);
    $lon0 = deg2rad(-102.0);
    $FE = 2500000.0;
    $FN = 0.0;

    $m = fn ($phi) => cos($phi) / sqrt(1 - $e2 * sin($phi) ** 2);
    $t = fn ($phi) => tan(M_PI / 4 - $phi / 2) / (((1 - $e * sin($phi)) / (1 + $e * sin($phi))) ** ($e / 2));

    $m1 = $m($lat1);
    $m2 = $m($lat2);
    $t0 = $t($lat0);
    $t1 = $t($lat1);
    $t2 = $t($lat2);

    $n = (log($m1) - log($m2)) / (log($t1) - log($t2));
    $F = $m1 / ($n * $t1 ** $n);
    $rho0 = $a * $F * $t0 ** $n;

    $xp = $x - $FE;
    $yp = $y - $FN;
    $rho = ($n < 0 ? -1 : 1) * sqrt($xp ** 2 + ($rho0 - $yp) ** 2);
    $tPrima = ($rho / ($a * $F)) ** (1 / $n);
    $theta = atan2($xp, $rho0 - $yp);

    $lon = $theta / $n + $lon0;

    $lat = M_PI / 2 - 2 * atan($tPrima);
    for ($i = 0; $i < 10; $i++) {
        $lat = M_PI / 2 - 2 * atan($tPrima * (((1 - $e * sin($lat)) / (1 + $e * sin($lat))) ** ($e / 2)));
    }

    return [rad2deg($lon), rad2deg($lat)]; // [lng, lat]
}

// --- Lector de DBF (dBase III), streaming por registro ---
function abrirDbf(string $path): array
{
    $f = fopen($path, 'rb');
    $header = fread($f, 32);
    $numRegistros = unpack('V', substr($header, 4, 4))[1];
    $tamHeader = unpack('v', substr($header, 8, 2))[1];
    $tamRegistro = unpack('v', substr($header, 10, 2))[1];

    $campos = [];
    while (true) {
        $campoRaw = fread($f, 32);
        if (ord($campoRaw[0]) === 0x0D) {
            break;
        }
        $nombre = strtolower(rtrim(strtok(substr($campoRaw, 0, 11), "\0")));
        $longitud = ord($campoRaw[16]);
        $campos[] = [$nombre, $longitud];
    }
    fseek($f, $tamHeader);

    return [$f, $campos, $tamRegistro, $numRegistros];
}

function leerFilaDbf($f, array $campos, int $tamRegistro): ?array
{
    $registro = fread($f, $tamRegistro);
    if ($registro === false || $registro === '') {
        return null;
    }
    $pos = 1; // byte 0 = marca de borrado
    $fila = [];
    foreach ($campos as [$nombre, $longitud]) {
        $fila[$nombre] = trim(substr($registro, $pos, $longitud));
        $pos += $longitud;
    }
    return $fila;
}

// --- Lector de Shapefile (.shp), solo Polygon (shape type 5) ---
function leerPoligonoShp($f): ?array
{
    $cabeceraRegistro = fread($f, 8);
    if ($cabeceraRegistro === false || strlen($cabeceraRegistro) < 8) {
        return null; // fin de archivo
    }
    $longitudPalabras = unpack('N', substr($cabeceraRegistro, 4, 4))[1];
    $contenido = fread($f, $longitudPalabras * 2);

    $tipoForma = unpack('V', substr($contenido, 0, 4))[1];
    if ($tipoForma === 0) {
        return []; // forma nula, sin anillos
    }

    $numParts = unpack('V', substr($contenido, 36, 4))[1];
    $numPoints = unpack('V', substr($contenido, 40, 4))[1];

    $indicesPartes = [];
    for ($i = 0; $i < $numParts; $i++) {
        $indicesPartes[] = unpack('V', substr($contenido, 44 + $i * 4, 4))[1];
    }
    $indicesPartes[] = $numPoints;

    $offsetPuntos = 44 + $numParts * 4;
    $puntos = [];
    for ($i = 0; $i < $numPoints; $i++) {
        [$x, $y] = array_values(unpack('d2', substr($contenido, $offsetPuntos + $i * 16, 16)));
        $puntos[] = [$x, $y];
    }

    $anillos = [];
    for ($i = 0; $i < $numParts; $i++) {
        $inicio = $indicesPartes[$i];
        $fin = $indicesPartes[$i + 1];
        $anillos[] = array_slice($puntos, $inicio, $fin - $inicio);
    }

    return $anillos;
}

/** Área con signo (fórmula del shoelace). Negativa = horario = anillo exterior (ESRI). */
function areaConSigno(array $anillo): float
{
    $area = 0.0;
    $n = count($anillo);
    for ($i = 0; $i < $n; $i++) {
        [$x1, $y1] = $anillo[$i];
        [$x2, $y2] = $anillo[($i + 1) % $n];
        $area += $x1 * $y2 - $x2 * $y1;
    }
    return $area / 2;
}

/** Agrupa anillos [exterior, hoyo, hoyo, exterior, ...] en polígonos (con sus hoyos). */
function agruparAnillos(array $anillos): array
{
    $poligonos = [];
    foreach ($anillos as $anillo) {
        if (areaConSigno($anillo) < 0 || empty($poligonos)) {
            $poligonos[] = [$anillo]; // nuevo anillo exterior
        } else {
            $poligonos[count($poligonos) - 1][] = $anillo; // hoyo del último exterior
        }
    }
    return $poligonos;
}

function anilloAWkt(array $anillo): string
{
    $puntos = array_map(fn ($p) => $p[0] . ' ' . $p[1], $anillo);
    if ($anillo[0] !== $anillo[count($anillo) - 1]) {
        $puntos[] = $puntos[0]; // cerrar el anillo si no viene cerrado
    }
    return '(' . implode(',', $puntos) . ')';
}

function poligonosAWkt(array $poligonos): string
{
    if (count($poligonos) === 1) {
        $anillosWkt = array_map('anilloAWkt', $poligonos[0]);
        return 'POLYGON(' . implode(',', $anillosWkt) . ')';
    }
    $partes = array_map(
        fn ($poligono) => '(' . implode(',', array_map('anilloAWkt', $poligono)) . ')',
        $poligonos
    );
    return 'MULTIPOLYGON(' . implode(',', $partes) . ')';
}

function normalizarNombre(string $nombre): string
{
    $nombre = function_exists('mb_strtoupper') ? mb_strtoupper(trim($nombre), 'UTF-8') : strtoupper(trim($nombre));
    $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre;
    $nombre = preg_replace('/[^A-Z0-9 ]/', '', $nombre);
    return preg_replace('/\s+/', ' ', $nombre);
}

// --- Índice de colonias por (clave_municipio, nombre normalizado) ---
$db = getDB();
$colonias = $db->query(
    'SELECT c.id, c.nombre, m.clave_inegi FROM colonias c JOIN municipios m ON m.id = c.municipio_id'
)->fetchAll();

$indice = [];
foreach ($colonias as $c) {
    $clave = $c['clave_inegi'] . '|' . normalizarNombre($c['nombre']);
    $indice[$clave] = (int) $c['id'];
}

$insertPoligono = $db->prepare(
    'INSERT INTO colonia_poligonos (colonia_id, poligono) VALUES (?, ST_GeomFromText(?))
     ON DUPLICATE KEY UPDATE poligono = VALUES(poligono)'
);
$updateCentroide = $db->prepare(
    'UPDATE colonias SET centroide = ST_Centroid(ST_GeomFromText(?)) WHERE id = ?'
);

$logPath = __DIR__ . '/logs/dcah_sin_match.txt';
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0775, true);
}
$logHandle = fopen($logPath, 'w');

[$dbfHandle, $campos, $tamRegistro, $numRegistros] = abrirDbf($dbfPath);
$shpHandle = fopen($shpPath, 'rb');
fseek($shpHandle, 100); // saltar cabecera principal del .shp (100 bytes)

$total = 0;
$conMatch = 0;

// Se recorre exactamente $numRegistros veces (el número declarado en la
// cabecera del .dbf) en vez de leer hasta que fread() devuelva vacío: los
// archivos DBF suelen traer un byte marcador de fin de archivo (0x1A) tras
// el último registro, que fread() devolvería como un "registro" corto y
// falso si no se acota el conteo — eso desalinearía el emparejamiento con
// el .shp en el último registro sin que sea un error real de los datos.
for ($i = 0; $i < $numRegistros; $i++) {
    $fila = leerFilaDbf($dbfHandle, $campos, $tamRegistro);
    if ($fila === null) {
        fwrite(STDERR, "Fin de archivo inesperado en el .dbf, registro $i de $numRegistros.\n");
        break;
    }

    $anillosCrudos = leerPoligonoShp($shpHandle);
    $total++;

    if ($anillosCrudos === null) {
        fwrite(STDERR, "Desalineación real entre .dbf y .shp en el registro $total — abortando.\n");
        break;
    }
    if (empty($anillosCrudos)) {
        continue; // forma nula
    }

    $nombreAsen = $fila['nom_asen'] ?? '';
    $claveMunicipio = ($fila['cve_ent'] ?? '') . ($fila['cve_mun'] ?? '');
    $clave = $claveMunicipio . '|' . normalizarNombre($nombreAsen);

    if (!isset($indice[$clave])) {
        fwrite($logHandle, "$nombreAsen\t$claveMunicipio\n");
        continue;
    }

    // Reproyectar cada anillo de metros (LCC) a grados (WGS84)
    $anillosReproyectados = array_map(
        fn ($anillo) => array_map(fn ($p) => lccInversa($p[0], $p[1]), $anillo),
        $anillosCrudos
    );
    $poligonos = agruparAnillos($anillosReproyectados);
    $wkt = poligonosAWkt($poligonos);

    $coloniaId = $indice[$clave];
    $insertPoligono->execute([$coloniaId, $wkt]);
    $updateCentroide->execute([$wkt, $coloniaId]);
    $conMatch++;

    if ($total % 5000 === 0) {
        echo "Procesados: $total (con match: $conMatch)\n";
    }
}

fclose($logHandle);
fclose($shpHandle);
fclose($dbfHandle);

$sinMatch = $total - $conMatch;
$porcentaje = $total > 0 ? round($conMatch * 100 / $total, 1) : 0;

echo "Registros DCAH procesados: $total (esperado 79,775)\n";
echo "Con match (polígono insertado): $conMatch\n";
echo "Sin match (ver $logPath): $sinMatch\n";
echo "Tasa de match sobre DCAH: $porcentaje%\n";
