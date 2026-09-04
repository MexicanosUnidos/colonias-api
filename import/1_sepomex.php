<?php
/**
 * Script CLI de importación del catálogo SEPOMEX.
 * Lee import/data/sepomex.csv, deduplica municipios por clave_inegi
 * (c_estado + c_mnpio) e inserta colonias con centroide vacío temporal.
 *
 * Uso: php import/1_sepomex.php
 */

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$csvPath = __DIR__ . '/data/sepomex.csv';
if (!is_file($csvPath)) {
    fwrite(STDERR, "No se encontró $csvPath. Descarga el CSV de SEPOMEX primero (ver M3.1).\n");
    exit(1);
}

function normalizarClave(string $estado, string $municipio): string
{
    return str_pad($estado, 2, '0', STR_PAD_LEFT) . str_pad($municipio, 3, '0', STR_PAD_LEFT);
}

$db = getDB();

$estadosPorClave = [];
$stmt = $db->query('SELECT id, clave FROM estados');
foreach ($stmt->fetchAll() as $row) {
    $estadosPorClave[$row['clave']] = (int) $row['id'];
}

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "No se pudo abrir $csvPath\n");
    exit(1);
}

// Detectar delimitador y encabezado
$primeraLinea = fgets($handle);
$delimitador = substr_count($primeraLinea, '|') > substr_count($primeraLinea, ',') ? '|' : ',';
rewind($handle);

$encabezado = fgetcsv($handle, 0, $delimitador);
$encabezado = array_map(fn ($h) => trim((string) $h), $encabezado);
$col = array_flip($encabezado);

$requeridas = ['d_asenta', 'd_tipo_asenta', 'd_mnpio', 'd_estado', 'c_estado', 'c_mnpio'];
foreach ($requeridas as $r) {
    if (!isset($col[$r])) {
        fwrite(STDERR, "Columna requerida '$r' no encontrada en el CSV.\n");
        exit(1);
    }
}

// El código postal real de cada colonia viene en "d_codigo" en el XML
// nacional de SEPOMEX (CPdescarga.xml). La columna "d_CP" NO es el CP
// de la colonia — es la clave de la oficina postal, mucho más genérica
// (~1,200 valores en todo el país, contra ~32,000 de d_codigo). Se
// prefiere d_codigo; d_CP queda solo como respaldo por si algún día se
// usa un CSV distinto que sí lo use correctamente.
$colCP = $col['d_codigo'] ?? $col['d_CP'] ?? null;
if ($colCP === null) {
    fwrite(STDERR, "No se encontró columna de código postal ('d_codigo' o 'd_CP') en el CSV.\n");
    exit(1);
}

$municipioCache = []; // clave_inegi => municipio_id
$insertMunicipio = $db->prepare(
    'INSERT INTO municipios (estado_id, clave_inegi, nombre) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)'
);
$selectMunicipio = $db->prepare('SELECT id FROM municipios WHERE clave_inegi = ?');
$insertColonia = $db->prepare(
    'INSERT INTO colonias (nombre, municipio_id, codigo_postal, tipo) VALUES (?, ?, ?, ?)'
);

$total = 0;
$sinEstado = 0;
$db->beginTransaction();

while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
    if (count($fila) < count($encabezado)) {
        continue;
    }

    $claveEstado = str_pad(trim($fila[$col['c_estado']]), 2, '0', STR_PAD_LEFT);
    $claveMunicipio = trim($fila[$col['c_mnpio']]);
    $nombreMunicipio = trim($fila[$col['d_mnpio']]);
    $nombreColonia = trim($fila[$col['d_asenta']]);
    $tipo = trim($fila[$col['d_tipo_asenta']]);
    $cp = str_pad(trim($fila[$colCP]), 5, '0', STR_PAD_LEFT);

    if (!isset($estadosPorClave[$claveEstado])) {
        $sinEstado++;
        continue;
    }
    $estadoId = $estadosPorClave[$claveEstado];

    $claveInegi = normalizarClave($claveEstado, $claveMunicipio);

    if (!isset($municipioCache[$claveInegi])) {
        $insertMunicipio->execute([$estadoId, $claveInegi, $nombreMunicipio]);
        $selectMunicipio->execute([$claveInegi]);
        $municipioCache[$claveInegi] = (int) $selectMunicipio->fetchColumn();
    }

    $insertColonia->execute([$nombreColonia, $municipioCache[$claveInegi], $cp, $tipo]);
    $total++;

    if ($total % 5000 === 0) {
        $db->commit();
        $db->beginTransaction();
        echo "Procesadas: $total\n";
    }
}

$db->commit();
fclose($handle);

echo "Importación completa. Colonias insertadas: $total\n";
if ($sinEstado > 0) {
    echo "Filas ignoradas por clave de estado desconocida: $sinEstado\n";
}
