<?php
/**
 * Convierte el material crudo del INE (paquetes "Base Geográfica Digital")
 * a un único CSV compatible con import/5_ine_secciones.php.
 *
 * Cada estado se descarga de https://cartografia.ine.mx/sige8/ como un
 * .zip que contiene un .7z, que a su vez contiene decenas de capas de
 * Shapefile (una por tipo de rasgo: ESCUELA, HOSPITAL, MANZANA, etc.).
 * De ahí solo interesan tres tablas .dbf (no hace falta la geometría
 * .shp para nada de esto):
 *   - SECCION.dbf    -> entidad, distrito_f, distrito_l, municipio, seccion
 *   - MUNICIPIO.dbf  -> entidad, municipio, nombre   (solo para logs/validación)
 *   - ENTIDAD.dbf    -> entidad, nombre              (solo para logs/validación)
 *
 * Requiere en el sistema (herramientas externas, no PHP): `unzip` y `7z`.
 * Esto corre una sola vez en una máquina de desarrollo, no en el hosting
 * compartido — igual que la conversión de shapefile a GeoJSON de M4.
 *
 * Uso: php import/5a_bgd_a_csv.php [carpeta_con_subcarpetas_por_estado]
 * Por default busca en ../Distritos-Secciones (donde el usuario ya
 * descargó los 32 paquetes bgd_N_Shapefile.zip, uno por carpeta de estado).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$baseDir = $argv[1] ?? __DIR__ . '/../Distritos-Secciones';
if (!is_dir($baseDir)) {
    fwrite(STDERR, "No se encontró la carpeta $baseDir\n");
    exit(1);
}

foreach (['unzip', '7z'] as $bin) {
    exec("which $bin", $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Falta el binario '$bin' en el sistema. Instálalo antes de correr este script.\n");
        exit(1);
    }
}

/**
 * Lector mínimo de DBF (dBase III), sin dependencias externas.
 * Suficiente para las tablas planas que usa el INE en este paquete.
 */
function leerDbf(string $path): array
{
    $f = fopen($path, 'rb');
    if ($f === false) {
        return [];
    }

    $header = fread($f, 32);
    $numRegistros = unpack('V', substr($header, 4, 4))[1];
    $tamHeader = unpack('v', substr($header, 8, 2))[1];
    $tamRegistro = unpack('v', substr($header, 10, 2))[1];

    $campos = [];
    while (true) {
        $campoRaw = fread($f, 32);
        if ($campoRaw === false || ord($campoRaw[0]) === 0x0D) {
            break;
        }
        $nombre = rtrim(strtok(substr($campoRaw, 0, 11), "\0"));
        $longitud = ord($campoRaw[16]);
        $campos[] = [$nombre, $longitud];
    }

    fseek($f, $tamHeader);
    $filas = [];
    for ($i = 0; $i < $numRegistros; $i++) {
        $registro = fread($f, $tamRegistro);
        if ($registro === false || $registro === '') {
            break;
        }
        $pos = 1; // byte 0 = marca de borrado
        $fila = [];
        foreach ($campos as [$nombre, $longitud]) {
            $fila[$nombre] = trim(substr($registro, $pos, $longitud));
            $pos += $longitud;
        }
        $filas[] = $fila;
    }
    fclose($f);
    return $filas;
}

$tmpBase = sys_get_temp_dir() . '/ine_bgd_' . getmypid();
mkdir($tmpBase, 0775, true);

$csvOut = __DIR__ . '/data/ine_secciones.csv';
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0775, true);
}
$out = fopen($csvOut, 'w');
fputcsv($out, ['seccion', 'clave_entidad', 'distrito_federal', 'distrito_local', 'clave_municipio']);

$carpetasEstado = array_filter(glob($baseDir . '/*'), 'is_dir');
sort($carpetasEstado);

$totalSecciones = 0;
$resumenPorEstado = [];

foreach ($carpetasEstado as $carpeta) {
    $zips = glob($carpeta . '/bgd_*_Shapefile.zip');
    if (empty($zips)) {
        continue;
    }
    $zip = $zips[0];
    $nombreEstadoCarpeta = basename($carpeta);

    $tmpEstado = $tmpBase . '/' . preg_replace('/[^a-zA-Z0-9]/', '_', $nombreEstadoCarpeta);
    mkdir($tmpEstado, 0775, true);

    exec('unzip -o ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmpEstado) . ' 2>&1', $salida, $code);
    if ($code !== 0) {
        fwrite(STDERR, "No se pudo descomprimir $zip\n");
        continue;
    }

    $archivos7z = glob($tmpEstado . '/*.7z');
    if (empty($archivos7z)) {
        fwrite(STDERR, "No se encontró .7z dentro de $zip\n");
        continue;
    }

    exec('7z x ' . escapeshellarg($archivos7z[0]) . ' -o' . escapeshellarg($tmpEstado . '/x') . ' -y 2>&1', $salida2, $code2);
    if ($code2 !== 0) {
        fwrite(STDERR, "No se pudo extraer el .7z de $nombreEstadoCarpeta\n");
        continue;
    }

    // El .7z trae una única subcarpeta con el nombre del estado.
    $subcarpetas = array_filter(glob($tmpEstado . '/x/*'), 'is_dir');
    if (empty($subcarpetas)) {
        fwrite(STDERR, "Estructura inesperada dentro del .7z de $nombreEstadoCarpeta\n");
        continue;
    }
    $carpetaDatos = reset($subcarpetas);

    $seccionDbf = $carpetaDatos . '/SECCION.dbf';
    if (!is_file($seccionDbf)) {
        fwrite(STDERR, "No se encontró SECCION.dbf para $nombreEstadoCarpeta\n");
        continue;
    }

    $secciones = leerDbf($seccionDbf);
    foreach ($secciones as $s) {
        $entidad = str_pad((string) (int) $s['entidad'], 2, '0', STR_PAD_LEFT);
        $seccion = str_pad((string) (int) $s['seccion'], 4, '0', STR_PAD_LEFT);
        $distritoFederal = (int) $s['distrito_f'];
        $distritoLocal = isset($s['distrito_l']) && $s['distrito_l'] !== '' ? (int) $s['distrito_l'] : '';
        $municipio = str_pad((string) (int) $s['municipio'], 3, '0', STR_PAD_LEFT);

        fputcsv($out, [$seccion, $entidad, $distritoFederal, $distritoLocal, $municipio]);
        $totalSecciones++;
    }

    $resumenPorEstado[$nombreEstadoCarpeta] = count($secciones);
    echo "$nombreEstadoCarpeta: " . count($secciones) . " secciones\n";
}

fclose($out);

// Limpieza de temporales
exec('rm -rf ' . escapeshellarg($tmpBase));

echo "\nTotal de estados procesados: " . count($resumenPorEstado) . " (esperado 32)\n";
echo "Total de secciones escritas: $totalSecciones (esperado 73,268 según cartografia.ine.mx/sige8)\n";
echo "CSV generado en: $csvOut\n";
