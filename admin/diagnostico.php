<?php
/**
 * Diagnóstico aislado de exec()/PHP CLI — para encontrar la causa exacta
 * de un "Internal Server Error" al usar el panel de administración.
 * No toca sesión/CSRF/BD del panel principal, para descartar esas capas.
 *
 * Uso: https://tu-dominio.com/admin/diagnostico.php?bin=/usr/local/bin/php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/admin_auth.php';
requireAdminAuth();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Info de PHP ===\n";
echo 'PHP_VERSION: ' . PHP_VERSION . "\n";
echo 'PHP_BINARY: ' . PHP_BINARY . "\n";
echo 'PHP_SAPI: ' . PHP_SAPI . "\n";
echo 'open_basedir: ' . (ini_get('open_basedir') ?: '(sin restricción)') . "\n";
echo 'disable_functions: ' . (ini_get('disable_functions') ?: '(ninguna)') . "\n";
echo 'exec existe: ' . (function_exists('exec') ? 'sí' : 'no') . "\n\n";

$bin = $_GET['bin'] ?? '/usr/local/bin/php';
echo "=== Probar exec() con: $bin ===\n";

echo "is_dir(): ";
try {
    var_export(is_dir($bin));
} catch (Throwable $e) {
    echo 'EXCEPCIÓN: ' . $e->getMessage();
}
echo "\n";

echo "is_file(): ";
try {
    var_export(is_file($bin));
} catch (Throwable $e) {
    echo 'EXCEPCIÓN: ' . $e->getMessage();
}
echo "\n";

echo "is_executable(): ";
try {
    var_export(is_executable($bin));
} catch (Throwable $e) {
    echo 'EXCEPCIÓN: ' . $e->getMessage();
}
echo "\n\n";

echo "=== exec(\"\$bin -v\") ===\n";
try {
    $salida = [];
    $codigo = null;
    exec(escapeshellarg($bin) . ' -v < /dev/null 2>&1', $salida, $codigo);
    echo "código de salida: " . var_export($codigo, true) . "\n";
    echo "salida:\n" . implode("\n", $salida) . "\n";
} catch (Throwable $e) {
    echo 'EXCEPCIÓN al llamar exec(): ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "\n=== Escritura de archivo (config/admin_php_bin.txt) ===\n";
$ruta = __DIR__ . '/../config/admin_php_bin.txt';
echo "Carpeta config/ escribible: " . var_export(is_writable(dirname($ruta)), true) . "\n";
try {
    $resultado = @file_put_contents($ruta . '.test', 'prueba');
    echo "file_put_contents() de prueba: " . var_export($resultado, true) . "\n";
    if ($resultado !== false) {
        @unlink($ruta . '.test');
    }
} catch (Throwable $e) {
    echo 'EXCEPCIÓN al escribir: ' . $e->getMessage() . "\n";
}

echo "\nListo.\n";
