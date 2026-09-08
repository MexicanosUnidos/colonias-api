<?php
/**
 * Calcula un centroide provisional para las colonias que aún no tienen
 * coordenadas, promediando el centroide de otras colonias con el mismo
 * código postal que sí lo tengan (por ejemplo, ya emparejadas con INEGI).
 *
 * Se puede correr varias veces: solo toca colonias con centroide NULL.
 *
 * Uso: php import/1b_centroide_provisional.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/_admin_run.php';

permitirSoloCli();

$db = getDB();

// Promedio de lat/lng por CP, usando solo colonias que ya tienen centroide
$promedios = $db->query(
    'SELECT codigo_postal, AVG(ST_Y(centroide)) AS lat, AVG(ST_X(centroide)) AS lng
     FROM colonias
     WHERE centroide IS NOT NULL
     GROUP BY codigo_postal'
)->fetchAll();

if (empty($promedios)) {
    echo "Ninguna colonia tiene centroide todavía; no hay nada de qué promediar.\n";
    echo "Corre este script después de importar al menos parte de los polígonos INEGI (M4).\n";
    return; // fin normal, no es un error -- funciona igual en CLI que incluido desde admin/
}

$update = $db->prepare(
    'UPDATE colonias SET centroide = POINT(?, ?)
     WHERE codigo_postal = ? AND centroide IS NULL'
);

$actualizadas = 0;
foreach ($promedios as $p) {
    if ($p['lat'] === null || $p['lng'] === null) {
        continue;
    }
    $update->execute([(float) $p['lng'], (float) $p['lat'], $p['codigo_postal']]);
    $actualizadas += $update->rowCount();
}

echo "Colonias actualizadas con centroide provisional (promedio por CP): $actualizadas\n";

$restantes = (int) $db->query('SELECT COUNT(*) FROM colonias WHERE centroide IS NULL')->fetchColumn();
echo "Colonias que siguen sin centroide (CP sin ninguna referencia): $restantes\n";
