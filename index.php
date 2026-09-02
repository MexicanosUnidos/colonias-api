<?php
/**
 * Router principal de ColoniasAPI.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/rate_limit.php';
require_once __DIR__ . '/helpers/cache.php';

$startTime = microtime(true);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
    $uri = substr($uri, strlen($scriptDir));
}
$uri = '/' . trim($uri, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Rutas públicas (sin API key)
$publicRoutes = [];
if (is_file(__DIR__ . '/handlers/health.php')) {
    $publicRoutes['GET /health'] = __DIR__ . '/handlers/health.php';
}

$routeKey = "$method $uri";
if (isset($publicRoutes[$routeKey])) {
    require $publicRoutes[$routeKey];
    exit;
}

// Ruta especial: /keys/crear se protege con ADMIN_SECRET, no con API key de proyecto
if ($uri === '/keys/crear' && $method === 'POST') {
    require __DIR__ . '/handlers/keys.php';
    exit;
}

// Todas las demás rutas requieren API key válida
$apiKeyRow = requireApiKey();
enforceRateLimit((int) $apiKeyRow['id']);

if (preg_match('#^/colonia/(\d+)$#', $uri, $m) && $method === 'GET') {
    $_GET['id'] = (int) $m[1];
    require __DIR__ . '/handlers/colonias.php';
    exit;
}

$routes = [
    'GET /estados' => __DIR__ . '/handlers/estados.php',
    'GET /municipios' => __DIR__ . '/handlers/municipios.php',
    'GET /buscar' => __DIR__ . '/handlers/buscar.php',
    'GET /geolocate' => __DIR__ . '/handlers/geolocate.php',
    'GET /distrito' => __DIR__ . '/handlers/distrito.php',
];

if (isset($routes[$routeKey]) && is_file($routes[$routeKey])) {
    require $routes[$routeKey];
    exit;
}

jsonResponse(false, 'Ruta no encontrada', 404);
