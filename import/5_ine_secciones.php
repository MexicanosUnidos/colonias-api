<?php
/**
 * Script CLI de importación del catálogo de Secciones Electorales del INE.
 * Lee import/data/ine_secciones.csv y llena tres tablas:
 *   - distritos_federales (valores únicos estado+número, detectados en el archivo)
 *   - distritos_locales   (idem, para distrito local)
 *   - secciones_electorales (una fila por sección, con sus FKs resueltas)
 *
 * A diferencia de la importación SEPOMEX/INEGI, aquí no hay geometría:
 * es un catálogo de texto plano (sección -> distrito), publicado por el
 * INE, no por el INEGI. Ver docs/PLAN.md sección 2.4 y milestone M8.
 *
 * Nombres de columna: el archivo real del INE puede no usar los mismos
 * nombres que se documentaron en el PLAN (ver M8.2). Por eso cada campo
 * se busca contra una lista de alias comunes en $aliases más abajo.
 * Si el catálogo real usa un nombre distinto, agregarlo a la lista.
 *
 * Uso: php import/5_ine_secciones.php
 */

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$csvPath = __DIR__ . '/data/ine_secciones.csv';
if (!is_file($csvPath)) {
    fwrite(STDERR, "No se encontró $csvPath. Descarga el catálogo de Secciones Electorales del INE primero (ver M8.1).\n");
    exit(1);
}

// M8.2: alias de columnas conocidos. Ajustar según el archivo real.
$aliases = [
    'seccion' => ['seccion', 'SECCION', 'CLAVE_SECCION', 'clave_seccion'],
    'clave_estado' => ['clave_entidad', 'entidad', 'ENTIDAD', 'CVE_ENT', 'clave_estado', 'ID_ESTADO'],
    'distrito_federal' => ['distrito_federal', 'DISTRITO_FEDERAL', 'DTO_FED', 'distrito_fed', 'DISTRITO_FED'],
    'cabecera_federal' => ['cabecera_distrital_federal', 'cabecera_federal', 'CABECERA_DTOFED'],
    'distrito_local' => ['distrito_local', 'DISTRITO_LOCAL', 'DTO_LOC', 'distrito_loc', 'DISTRITO_LOC'],
    'cabecera_local' => ['cabecera_distrital_local', 'cabecera_local', 'CABECERA_DTOLOC'],
    'clave_municipio' => ['clave_municipio', 'municipio', 'MUNICIPIO', 'CVE_MUN', 'ID_MUNICIPIO'],
];

$db = getDB();

$estadosPorClave = [];
foreach ($db->query('SELECT id, clave FROM estados')->fetchAll() as $row) {
    $estadosPorClave[$row['clave']] = (int) $row['id'];
}

$municipiosPorClaveInegi = [];
foreach ($db->query('SELECT id, clave_inegi FROM municipios')->fetchAll() as $row) {
    $municipiosPorClaveInegi[$row['clave_inegi']] = (int) $row['id'];
}

function abrirCsv(string $path, array &$col, array $aliases): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        fwrite(STDERR, "No se pudo abrir $path\n");
        exit(1);
    }

    $primeraLinea = fgets($handle);
    $delimitador = substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';
    rewind($handle);

    $encabezado = fgetcsv($handle, 0, $delimitador);
    $encabezado = array_map(fn ($h) => trim((string) $h), $encabezado);
    $posiciones = array_flip($encabezado);

    foreach ($aliases as $campo => $nombres) {
        foreach ($nombres as $nombre) {
            if (isset($posiciones[$nombre])) {
                $col[$campo] = $posiciones[$nombre];
                continue 2;
            }
        }
    }

    return [$handle, $delimitador];
}

$col = [];
[$handle, $delimitador] = abrirCsv($csvPath, $col, $aliases);

foreach (['seccion', 'clave_estado', 'distrito_federal'] as $requerido) {
    if (!isset($col[$requerido])) {
        fwrite(STDERR, "No se encontró una columna para '$requerido'. Revisa el encabezado real del CSV y agrega el nombre a \$aliases en este script (ver M8.2).\n");
        exit(1);
    }
}

// M8.7: conteo inicial, sin insertar nada todavía.
$totalFilas = 0;
while (fgetcsv($handle, 0, $delimitador) !== false) {
    $totalFilas++;
}
echo "Filas detectadas en el CSV: $totalFilas\n";
rewind($handle);
fgetcsv($handle, 0, $delimitador); // saltar encabezado otra vez

// M8.8 + M8.9 + M8.10: primera pasada — resolver estado por fila y
// recolectar los pares únicos (estado_id, numero) de distrito federal/local.
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
$logSinEstado = fopen($logDir . '/secciones_sin_estado.txt', 'w');

$federalesUnicos = []; // "estadoId|numero" => cabecera
$localesUnicos = [];   // "estadoId|numero" => cabecera
$filasValidas = 0;
$sinEstado = 0;

while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
    $claveEstado = str_pad(trim((string) ($fila[$col['clave_estado']] ?? '')), 2, '0', STR_PAD_LEFT);
    if (!isset($estadosPorClave[$claveEstado])) {
        $sinEstado++;
        fwrite($logSinEstado, implode(',', $fila) . "\n");
        continue;
    }
    $estadoId = $estadosPorClave[$claveEstado];
    $filasValidas++;

    $numFederal = (int) trim((string) ($fila[$col['distrito_federal']] ?? 0));
    if ($numFederal > 0) {
        $llave = $estadoId . '|' . $numFederal;
        $federalesUnicos[$llave] = isset($col['cabecera_federal'])
            ? trim((string) ($fila[$col['cabecera_federal']] ?? ''))
            : null;
    }

    if (isset($col['distrito_local'])) {
        $numLocal = (int) trim((string) ($fila[$col['distrito_local']] ?? 0));
        if ($numLocal > 0) {
            $llave = $estadoId . '|' . $numLocal;
            $localesUnicos[$llave] = isset($col['cabecera_local'])
                ? trim((string) ($fila[$col['cabecera_local']] ?? ''))
                : null;
        }
    }
}
fclose($logSinEstado);

echo "Filas con estado reconocido: $filasValidas\n";
echo "Filas sin estado reconocido (ver logs/secciones_sin_estado.txt): $sinEstado\n";

// M8.9: insertar distritos federales únicos.
$insertFederal = $db->prepare(
    'INSERT INTO distritos_federales (estado_id, numero, cabecera) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE cabecera = VALUES(cabecera)'
);
foreach ($federalesUnicos as $llave => $cabecera) {
    [$estadoId, $numero] = explode('|', $llave);
    $insertFederal->execute([(int) $estadoId, (int) $numero, $cabecera ?: null]);
}
echo 'Distritos federales insertados/actualizados: ' . count($federalesUnicos) . "\n";

// M8.10: insertar distritos locales únicos.
$insertLocal = $db->prepare(
    'INSERT INTO distritos_locales (estado_id, numero, cabecera) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE cabecera = VALUES(cabecera)'
);
foreach ($localesUnicos as $llave => $cabecera) {
    [$estadoId, $numero] = explode('|', $llave);
    $insertLocal->execute([(int) $estadoId, (int) $numero, $cabecera ?: null]);
}
echo 'Distritos locales insertados/actualizados: ' . count($localesUnicos) . "\n";

// Cargar los IDs recién insertados para resolver las FKs en la segunda pasada.
$federalIdPorClave = [];
foreach ($db->query('SELECT id, estado_id, numero FROM distritos_federales')->fetchAll() as $row) {
    $federalIdPorClave[$row['estado_id'] . '|' . $row['numero']] = (int) $row['id'];
}
$localIdPorClave = [];
foreach ($db->query('SELECT id, estado_id, numero FROM distritos_locales')->fetchAll() as $row) {
    $localIdPorClave[$row['estado_id'] . '|' . $row['numero']] = (int) $row['id'];
}

// M8.11: segunda pasada — insertar cada sección en lotes.
rewind($handle);
fgetcsv($handle, 0, $delimitador); // saltar encabezado

$insertSeccion = $db->prepare(
    'INSERT INTO secciones_electorales (seccion, estado_id, distrito_federal_id, distrito_local_id, municipio_id)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        distrito_federal_id = VALUES(distrito_federal_id),
        distrito_local_id = VALUES(distrito_local_id),
        municipio_id = VALUES(municipio_id)'
);

$totalInsertadas = 0;
$db->beginTransaction();

while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
    $claveEstado = str_pad(trim((string) ($fila[$col['clave_estado']] ?? '')), 2, '0', STR_PAD_LEFT);
    if (!isset($estadosPorClave[$claveEstado])) {
        continue; // ya se registró en el log de la primera pasada
    }
    $estadoId = $estadosPorClave[$claveEstado];

    $seccion = str_pad(trim((string) ($fila[$col['seccion']] ?? '')), 4, '0', STR_PAD_LEFT);

    $numFederal = (int) trim((string) ($fila[$col['distrito_federal']] ?? 0));
    $distritoFederalId = $federalIdPorClave[$estadoId . '|' . $numFederal] ?? null;
    if ($distritoFederalId === null) {
        continue; // sin distrito federal válido, no cumple la FK NOT NULL
    }

    $distritoLocalId = null;
    if (isset($col['distrito_local'])) {
        $numLocal = (int) trim((string) ($fila[$col['distrito_local']] ?? 0));
        $distritoLocalId = $localIdPorClave[$estadoId . '|' . $numLocal] ?? null;
    }

    $municipioId = null;
    if (isset($col['clave_municipio'])) {
        $claveMunicipio = trim((string) ($fila[$col['clave_municipio']] ?? ''));
        $claveInegi = $claveEstado . str_pad($claveMunicipio, 3, '0', STR_PAD_LEFT);
        $municipioId = $municipiosPorClaveInegi[$claveInegi] ?? null;
    }

    $insertSeccion->execute([$seccion, $estadoId, $distritoFederalId, $distritoLocalId, $municipioId]);
    $totalInsertadas++;

    if ($totalInsertadas % 500 === 0) {
        $db->commit();
        $db->beginTransaction();
        echo "Secciones procesadas: $totalInsertadas\n";
    }
}

$db->commit();
fclose($handle);

echo "Importación completa. Secciones insertadas/actualizadas: $totalInsertadas\n";
