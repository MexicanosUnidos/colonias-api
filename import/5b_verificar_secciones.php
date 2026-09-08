<?php
/**
 * Verifica los conteos esperados tras la importación del catálogo de
 * Secciones Electorales del INE (M8.12).
 * Uso: php import/5b_verificar_secciones.php
 */

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$db = getDB();

$secciones = (int) $db->query('SELECT COUNT(*) FROM secciones_electorales')->fetchColumn();
$distritosFederales = (int) $db->query('SELECT COUNT(*) FROM distritos_federales')->fetchColumn();
$distritosLocales = (int) $db->query('SELECT COUNT(*) FROM distritos_locales')->fetchColumn();
$sinDistritoFederal = (int) $db->query(
    'SELECT COUNT(*) FROM secciones_electorales WHERE distrito_federal_id IS NULL'
)->fetchColumn();
$distritosFederalesSinSecciones = (int) $db->query(
    'SELECT COUNT(*) FROM distritos_federales df
     LEFT JOIN secciones_electorales se ON se.distrito_federal_id = df.id
     WHERE se.seccion IS NULL'
)->fetchColumn();

echo "secciones_electorales:              $secciones (esperado 73,268 — total nacional publicado en cartografia.ine.mx/sige8)\n";
echo "distritos_federales:                $distritosFederales (esperado = 300)\n";
echo "distritos_locales:                  $distritosLocales (varía por entidad)\n";
echo "secciones sin distrito_federal_id:  $sinDistritoFederal (esperado 0, la columna es NOT NULL)\n";
echo "distritos federales sin secciones:  $distritosFederalesSinSecciones (esperado 0)\n";

$ok = $secciones > 0 && $sinDistritoFederal === 0 && $distritosFederalesSinSecciones === 0;
echo $ok ? "OK\n" : "REVISAR: alguno de los conteos no cumple lo esperado.\n";
exit($ok ? 0 : 1);
