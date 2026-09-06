<?php
/**
 * Conexión PDO singleton y helper de respuesta JSON.
 */

function env(): array
{
    static $env = null;
    if ($env === null) {
        $path = __DIR__ . '/env.php';
        if (!file_exists($path)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Falta config/env.php. Copia config/env.example.php y llénalo.',
                'codigo' => 500,
            ]);
            exit;
        }
        $env = require $path;
    }
    return $env;
}

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $e = env();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $e['DB_HOST'],
            $e['DB_PORT'],
            $e['DB_NAME']
        );
        $pdo = new PDO($dsn, $e['DB_USER'], $e['DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

/**
 * Envía una respuesta JSON estándar y termina la ejecución.
 */
function jsonResponse(bool $ok, $dataOrError, int $codigo = 200, ?float $startTime = null): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');

    $payload = ['ok' => $ok];

    if ($ok) {
        $payload['data'] = $dataOrError;
        if (is_array($dataOrError) && array_is_list($dataOrError)) {
            $payload['total'] = count($dataOrError);
        }
        if ($startTime !== null) {
            $payload['tiempo_ms'] = (int) round((microtime(true) - $startTime) * 1000);
        }
    } else {
        $payload['error'] = $dataOrError;
        $payload['codigo'] = $codigo;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // json_encode() puede fallar en silencio (ej. texto que no es UTF-8
        // válido) y dejar el body vacío con HTTP 200 -- eso es peor que un
        // error visible: parece éxito pero no trae nada. Nunca dejarlo así.
        http_response_code(500);
        $json = json_encode([
            'ok' => false,
            'error' => 'Error interno al generar la respuesta JSON: ' . json_last_error_msg(),
            'codigo' => 500,
        ]);
    }

    echo $json;
    exit;
}
