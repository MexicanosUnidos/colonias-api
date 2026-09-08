<?php
/**
 * Gate de acceso para herramientas internas (test.php y admin/).
 * No es parte del API pública — usa el mismo ADMIN_SECRET que ya
 * protege POST /keys/crear (config/env.php), guardado en sesión tras
 * el primer login para no pedirlo en cada clic.
 *
 * Uso, al inicio del archivo protegido:
 *   require_once __DIR__ . '/../config/db.php';
 *   require_once __DIR__ . '/../middleware/admin_auth.php';
 *   requireAdminAuth();
 */

function requireAdminAuth(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $secret = (string) (env()['ADMIN_SECRET'] ?? '');

    if (isset($_POST['clave_admin']) && $secret !== '' && hash_equals($secret, (string) $_POST['clave_admin'])) {
        $_SESSION['admin_autenticado'] = true;
    }

    if (isset($_SESSION['admin_autenticado']) && $_SESSION['admin_autenticado'] === true) {
        return;
    }

    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    $huboIntento = isset($_POST['clave_admin']);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acceso restringido</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f6f8; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  form { background: #fff; border: 1px solid #e0e2e7; border-radius: 10px; padding: 28px 32px; width: 300px; }
  h1 { font-size: 1.1rem; margin: 0 0 16px; }
  input { width: 100%; padding: 9px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box; margin-bottom: 12px; }
  button { width: 100%; padding: 9px; border: none; border-radius: 6px; background: #2563eb; color: #fff; font-size: 0.9rem; cursor: pointer; }
  p.err { color: #dc2626; font-size: 0.85rem; margin: -6px 0 12px; }
</style>
</head>
<body>
  <form method="post">
    <h1>🔒 Acceso restringido</h1>
    <?php if ($huboIntento): ?>
      <p class="err">Clave incorrecta.</p>
    <?php endif; ?>
    <input type="password" name="clave_admin" placeholder="Clave de administrador" autofocus>
    <button type="submit">Entrar</button>
  </form>
</body>
</html>
    <?php
    exit;
}
