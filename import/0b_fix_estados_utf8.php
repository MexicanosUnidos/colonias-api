<?php
/**
 * Corrige el encoding de la tabla `estados`.
 *
 * Causa real encontrada en producción: al importar schema.sql por
 * phpMyAdmin, los 32 nombres (con acentos: México, Querétaro, etc.)
 * quedaron con bytes que no son UTF-8 válido -- típico problema del
 * charset que usa el importador de phpMyAdmin al leer el archivo, no
 * del contenido del archivo en sí (que es UTF-8 real). Efecto: cualquier
 * endpoint que devuelva `estados.nombre` (incluido vía JOIN, como
 * /colonia/{id}) falla con json_encode() devolviendo false -- ver el
 * fix de jsonResponse() en config/db.php que hizo visible este error
 * ("Malformed UTF-8 characters, possibly incorrectly encoded").
 *
 * Este script reescribe los 32 nombres directo desde PHP (mismo canal
 * PDO con charset=utf8mb4 que ya usa el resto del proyecto, y que NO
 * tiene este problema -- por eso colonias/municipios, importados por
 * PDO en vez de phpMyAdmin, están bien). Es seguro correrlo las veces
 * que haga falta: solo hace UPDATE por clave.
 *
 * Uso: php import/0b_fix_estados_utf8.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/_admin_run.php';

permitirSoloCli();

$db = getDB();

$estados = [
    '01' => 'Aguascalientes',
    '02' => 'Baja California',
    '03' => 'Baja California Sur',
    '04' => 'Campeche',
    '05' => 'Coahuila',
    '06' => 'Colima',
    '07' => 'Chiapas',
    '08' => 'Chihuahua',
    '09' => 'Ciudad de México',
    '10' => 'Durango',
    '11' => 'Guanajuato',
    '12' => 'Guerrero',
    '13' => 'Hidalgo',
    '14' => 'Jalisco',
    '15' => 'Estado de México',
    '16' => 'Michoacán',
    '17' => 'Morelos',
    '18' => 'Nayarit',
    '19' => 'Nuevo León',
    '20' => 'Oaxaca',
    '21' => 'Puebla',
    '22' => 'Querétaro',
    '23' => 'Quintana Roo',
    '24' => 'San Luis Potosí',
    '25' => 'Sinaloa',
    '26' => 'Sonora',
    '27' => 'Tabasco',
    '28' => 'Tamaulipas',
    '29' => 'Tlaxcala',
    '30' => 'Veracruz',
    '31' => 'Yucatán',
    '32' => 'Zacatecas',
];

$update = $db->prepare('UPDATE estados SET nombre = ? WHERE clave = ?');
$actualizados = 0;
foreach ($estados as $clave => $nombre) {
    $update->execute([$nombre, $clave]);
    $actualizados += $update->rowCount();
}

echo "Filas realmente modificadas: $actualizados de " . count($estados) . " (0 aquí es normal si ya estaban bien y se corre de nuevo)\n";

// Verificación inmediata: confirmar que json_encode ya no falla con estos datos.
$todos = $db->query('SELECT id, clave, nombre FROM estados')->fetchAll();
$json = json_encode($todos, JSON_UNESCAPED_UNICODE);

if ($json === false) {
    abortarImport('SIGUE FALLANDO tras la corrección: ' . json_last_error_msg());
}

echo "Verificado: json_encode() ya funciona correctamente para los 32 estados.\n";
echo "Prueba ahora GET /estados -- debería responder normal.\n";
