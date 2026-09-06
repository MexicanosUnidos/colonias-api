<?php
/**
 * Helper compartido para que los scripts de import/ puedan correr tanto
 * por CLI normal (ver README) como incluidos en el mismo proceso PHP
 * desde admin/index.php — necesario en hostings donde exec()/Cron Jobs
 * no están disponibles (caso real encontrado en producción).
 *
 * En modo admin (constante COLONIAS_MODO_ADMIN definida por admin/index.php
 * antes de hacer el include), un error usa una excepción en vez de exit():
 * exit() dentro de un include mataría también al panel que lo llamó, no
 * solo al script.
 */

function abortarImport(string $mensaje): void
{
    if (defined('COLONIAS_MODO_ADMIN')) {
        throw new RuntimeException($mensaje);
    }
    fwrite(STDERR, $mensaje . "\n");
    exit(1);
}

function permitirSoloCli(): void
{
    if (defined('COLONIAS_MODO_ADMIN')) {
        return;
    }
    if (PHP_SAPI !== 'cli') {
        abortarImport('Este script solo puede ejecutarse por CLI.');
    }
}
