<?php
/**
 * Panel de administración — poblar la base de datos paso a paso.
 *
 * Cada paso se muestra con su estado actual (ya hecho / pendiente /
 * falta archivo fuente) ANTES de ofrecer el botón para correrlo, y
 * los pasos que no son seguros de repetir (duplicarían datos) piden
 * una confirmación extra ("forzar") para volver a correrse.
 *
 * No requiere SSH ni exec()/shell_exec(): los scripts de import/ se
 * corren *dentro del mismo proceso PHP* de este panel (con include),
 * no como subproceso — así funciona incluso en hostings que deshabilitan
 * exec() por completo (caso real encontrado en producción). El precio es
 * que el import comparte el límite de tiempo de ejecución del propio
 * request web; se intenta subirlo, pero en hostings muy restrictivos un
 * import grande (SEPOMEX, INEGI) podría no alcanzar a terminar — en ese
 * caso, la única alternativa es un Cron Job si tu plan lo incluye.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/admin_auth.php';
requireAdminAuth();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Token simple contra envíos de formulario ajenos al propio panel.
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['admin_csrf'];

/**
 * Corre un script de import/ en el mismo proceso PHP (sin exec()).
 * Define COLONIAS_MODO_ADMIN para que el script use excepciones en vez
 * de exit() en sus rutas de error (ver import/_admin_run.php) — exit()
 * dentro de un include mataría también a este panel, no solo al script.
 * Se ejecuta dentro de una función para que las variables/funciones del
 * script no se mezclen con las de esta página.
 *
 * Nota: si en el futuro un mismo request llegara a correr dos scripts
 * (hoy no pasa: cada request solo corre uno y redirige), un segundo
 * include del mismo archivo fallaría por funciones duplicadas — no es
 * un caso alcanzable con el flujo actual de este panel.
 */
function ejecutarImportEnProceso(string $rutaScript): string
{
    if (!defined('COLONIAS_MODO_ADMIN')) {
        define('COLONIAS_MODO_ADMIN', true);
    }
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    ob_start();
    try {
        include $rutaScript;
    } catch (Throwable $e) {
        $parcial = ob_get_clean();
        throw new RuntimeException(trim($parcial . "\n\nERROR: " . $e->getMessage()));
    }
    return trim(ob_get_clean());
}

function carpetaConArchivos(string $dir, string $patron = '*'): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    return count(glob(rtrim($dir, '/') . '/' . $patron)) > 0;
}

function contar(PDO $db, string $sql): int
{
    try {
        return (int) $db->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

$db = getDB();
$dataDir = __DIR__ . '/../import/data';
$raiz = dirname(__DIR__);

// --- Manejo de acciones (POST) — patrón Post/Redirect/Get ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['admin_mensaje'] = ['tipo' => 'error', 'texto' => 'Token de formulario inválido, intenta de nuevo.'];
        header('Location: index.php');
        exit;
    }

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_key') {
        $proyecto = trim($_POST['proyecto'] ?? '');
        if ($proyecto === '') {
            $_SESSION['admin_mensaje'] = ['tipo' => 'error', 'texto' => 'El nombre del proyecto es requerido.'];
        } else {
            $apiKey = 'col_' . bin2hex(random_bytes(32));
            $hash = hash('sha256', $apiKey);
            $db->prepare('INSERT INTO api_keys (proyecto, key_hash, activa) VALUES (?, ?, 1)')
                ->execute([$proyecto, $hash]);
            $_SESSION['admin_mensaje'] = [
                'tipo' => 'ok',
                'texto' => "API key creada para \"$proyecto\". Cópiala ahora, no se puede recuperar después:",
                'key' => $apiKey,
            ];
        }
        header('Location: index.php');
        exit;
    }

    if ($accion === 'correr_paso') {
        $pasoId = $_POST['paso'] ?? '';
        $forzar = isset($_POST['forzar']);

        $scriptsPermitidos = [
            'sepomex' => 'import/1_sepomex.php',
            'centroide_provisional' => 'import/1b_centroide_provisional.php',
            'inegi' => 'import/2_dcah_geo.php',
            'secciones_ine' => 'import/5_ine_secciones.php',
        ];

        if (!isset($scriptsPermitidos[$pasoId])) {
            $_SESSION['admin_mensaje'] = ['tipo' => 'error', 'texto' => 'Paso desconocido.'];
            header('Location: index.php');
            exit;
        }

        // Re-verificar estado justo antes de correr (no confiar en la UI).
        $coloniasCount = contar($db, 'SELECT COUNT(*) FROM colonias');

        $yaHecho = [
            'sepomex' => $coloniasCount > 0,
            'centroide_provisional' => false, // siempre idempotente, nunca bloquea
            'inegi' => false,                 // usa ON DUPLICATE KEY UPDATE, nunca bloquea
            'secciones_ine' => false,          // usa ON DUPLICATE KEY UPDATE, nunca bloquea
        ];
        $noIdempotente = ['sepomex' => true];

        if (($yaHecho[$pasoId] ?? false) && !empty($noIdempotente[$pasoId]) && !$forzar) {
            $_SESSION['admin_mensaje'] = [
                'tipo' => 'error',
                'texto' => 'Este paso ya se corrió antes (' . $coloniasCount . ' colonias) y volver a correrlo duplicaría datos. Marca "forzar de todos modos" si de verdad quieres repetirlo.',
            ];
            header('Location: index.php');
            exit;
        }

        $rutaScript = $raiz . '/' . $scriptsPermitidos[$pasoId];
        $inicio = microtime(true);
        try {
            $salida = ejecutarImportEnProceso($rutaScript);
            $duracion = round(microtime(true) - $inicio, 1);
            $_SESSION['admin_mensaje'] = [
                'tipo' => 'ok',
                'texto' => "Terminó en {$duracion}s:",
                'salida' => $salida !== '' ? $salida : '(sin salida)',
            ];
        } catch (Throwable $e) {
            $duracion = round(microtime(true) - $inicio, 1);
            $_SESSION['admin_mensaje'] = [
                'tipo' => 'error',
                'texto' => "Falló después de {$duracion}s:",
                'salida' => $e->getMessage(),
            ];
        }
        header('Location: index.php');
        exit;
    }

    header('Location: index.php');
    exit;
}

// --- Mensaje de la acción anterior (si vino de un redirect) ---
$mensaje = $_SESSION['admin_mensaje'] ?? null;
unset($_SESSION['admin_mensaje']);

// --- Estado actual de la base de datos ---
$conteos = [
    'estados' => contar($db, 'SELECT COUNT(*) FROM estados'),
    'municipios' => contar($db, 'SELECT COUNT(*) FROM municipios'),
    'colonias' => contar($db, 'SELECT COUNT(*) FROM colonias'),
    'colonias_con_centroide' => contar($db, 'SELECT COUNT(*) FROM colonias WHERE centroide IS NOT NULL'),
    'colonia_poligonos' => contar($db, 'SELECT COUNT(*) FROM colonia_poligonos'),
    'distritos_federales' => contar($db, 'SELECT COUNT(*) FROM distritos_federales'),
    'distritos_locales' => contar($db, 'SELECT COUNT(*) FROM distritos_locales'),
    'secciones_electorales' => contar($db, 'SELECT COUNT(*) FROM secciones_electorales'),
    'api_keys' => contar($db, 'SELECT COUNT(*) FROM api_keys'),
];

$pasos = [
    [
        'id' => 'sepomex',
        'nombre' => 'SEPOMEX — colonias y municipios',
        'script' => 'import/1_sepomex.php',
        'hecho' => $conteos['colonias'] > 0,
        'detalle' => number_format($conteos['colonias']) . ' colonias (esperado > 140,000)',
        'archivo' => $dataDir . '/sepomex.csv',
        'archivo_ok' => is_file($dataDir . '/sepomex.csv'),
        'idempotente' => false,
        'nota' => 'Carga ~145,000 colonias. Tarda varios minutos. Si corre dos veces, duplica colonias.',
    ],
    [
        'id' => 'centroide_provisional',
        'nombre' => 'Centroide provisional (promedio por CP)',
        'script' => 'import/1b_centroide_provisional.php',
        'hecho' => $conteos['colonias_con_centroide'] > 0,
        'detalle' => number_format($conteos['colonias_con_centroide']) . ' colonias con centroide',
        'archivo' => null,
        'archivo_ok' => true,
        'idempotente' => true,
        'nota' => 'Seguro de correr varias veces; solo toca colonias que todavía no tienen centroide.',
    ],
    [
        'id' => 'inegi',
        'nombre' => 'INEGI (DCAH) — polígonos geográficos',
        'script' => 'import/2_dcah_geo.php',
        'hecho' => $conteos['colonia_poligonos'] > 0,
        'detalle' => number_format($conteos['colonia_poligonos']) . ' polígonos (~41.5% de match esperado sobre DCAH, ver PLAN.md 2.2)',
        'archivo' => $raiz . '/Poligonos/00_integrados/conjunto_de_datos/00as.dbf',
        'archivo_ok' => is_file($raiz . '/Poligonos/00_integrados/conjunto_de_datos/00as.dbf')
            && is_file($raiz . '/Poligonos/00_integrados/conjunto_de_datos/00as.shp'),
        'idempotente' => true,
        'nota' => 'Usa ON DUPLICATE KEY UPDATE — seguro de re-correr. El más pesado: puede tardar varios minutos.',
    ],
    [
        'id' => 'secciones_ine',
        'nombre' => 'INE — secciones electorales (distrito federal/local)',
        'script' => 'import/5_ine_secciones.php',
        'hecho' => $conteos['secciones_electorales'] > 0,
        'detalle' => number_format($conteos['secciones_electorales']) . ' secciones (esperado 73,268)',
        'archivo' => $dataDir . '/ine_secciones.csv',
        'archivo_ok' => is_file($dataDir . '/ine_secciones.csv'),
        'idempotente' => true,
        'nota' => 'Usa ON DUPLICATE KEY UPDATE — seguro de re-correr.',
    ],
];

$apiKeys = $db->query('SELECT id, proyecto, activa, ultimo_uso, creado_en FROM api_keys ORDER BY creado_en DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ColoniasAPI — Administración</title>
<style>
  :root {
    --bg: #f5f6f8; --panel: #fff; --border: #e0e2e7; --text: #1b1e24; --muted: #6b7280;
    --accent: #2563eb; --ok: #16a34a; --ok-bg: #f0fdf4; --warn: #b45309; --warn-bg: #fffbeb;
    --err: #dc2626; --err-bg: #fef2f2; --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 24px; }
  .wrap { max-width: 900px; margin: 0 auto; }
  .topbar { display: flex; justify-content: space-between; align-items: baseline; }
  h1 { font-size: 1.4rem; margin-bottom: 4px; }
  .topbar a { font-size: 0.82rem; color: var(--accent); text-decoration: none; }
  .sub { color: var(--muted); margin-bottom: 20px; font-size: 0.9rem; }

  .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; }
  .panel h2 { font-size: 1rem; margin: 0 0 12px; }

  .grid-conteos { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
  .stat { background: var(--bg); border-radius: 8px; padding: 10px 12px; }
  .stat .n { font-size: 1.2rem; font-weight: 700; font-family: var(--mono); }
  .stat .l { font-size: 0.75rem; color: var(--muted); }

  .aviso { border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 0.88rem; }
  .aviso.ok { background: var(--ok-bg); color: #166534; border: 1px solid #bbf7d0; }
  .aviso.error { background: var(--err-bg); color: #991b1b; border: 1px solid #fecaca; }
  .aviso pre { white-space: pre-wrap; word-break: break-word; margin: 8px 0 0; font-family: var(--mono); font-size: 0.78rem; max-height: 260px; overflow: auto; }
  .aviso code.key { display: block; background: #0f1115; color: #d1fae5; padding: 8px 10px; border-radius: 6px; margin-top: 8px; font-family: var(--mono); font-size: 0.82rem; user-select: all; }

  .paso { border: 1px solid var(--border); border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; }
  .paso-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
  .paso-head strong { font-size: 0.92rem; }
  .badge { font-size: 0.72rem; padding: 2px 9px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
  .badge.hecho { background: var(--ok-bg); color: var(--ok); }
  .badge.pendiente { background: #eef2ff; color: var(--accent); }
  .badge.falta { background: var(--warn-bg); color: var(--warn); }
  .paso .detalle { color: var(--muted); font-size: 0.82rem; margin-top: 4px; }
  .paso .nota { color: var(--muted); font-size: 0.78rem; margin-top: 4px; font-style: italic; }
  .paso form { margin-top: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .paso label.forzar { font-size: 0.78rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }

  button { padding: 8px 16px; border: none; border-radius: 6px; background: var(--accent); color: white; font-size: 0.85rem; cursor: pointer; }
  button:hover { opacity: 0.9; }
  button.secondary { background: #4b5563; }
  input[type=text] { padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem; }

  table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--border); }
  th { color: var(--muted); font-weight: 600; font-size: 0.78rem; }
  .activa-si { color: var(--ok); font-weight: 600; }
  .activa-no { color: var(--err); font-weight: 600; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <h1>⚙ ColoniasAPI — Administración</h1>
    <a href="../test.php">🧪 Panel de pruebas</a>
  </div>
  <p class="sub">Población de datos paso a paso. Cada paso muestra su estado actual antes de ofrecer el botón para correrlo. Los scripts corren dentro de este mismo panel (sin exec()/Cron Job) — si tu hosting mata requests muy largos, un paso pesado podría no alcanzar a terminar; en ese caso, la salida parcial te dice hasta dónde llegó.</p>

  <?php if ($mensaje): ?>
    <div class="aviso <?= $mensaje['tipo'] === 'ok' ? 'ok' : 'error' ?>">
      <?= htmlspecialchars($mensaje['texto']) ?>
      <?php if (!empty($mensaje['key'])): ?>
        <code class="key"><?= htmlspecialchars($mensaje['key']) ?></code>
      <?php endif; ?>
      <?php if (!empty($mensaje['salida'])): ?>
        <pre><?= htmlspecialchars($mensaje['salida']) ?></pre>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Estado actual de la base de datos</h2>
    <div class="grid-conteos">
      <?php foreach ($conteos as $nombre => $valor): ?>
        <div class="stat">
          <div class="n"><?= $valor >= 0 ? number_format($valor) : '—' ?></div>
          <div class="l"><?= htmlspecialchars(str_replace('_', ' ', $nombre)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="panel">
    <h2>Pasos de importación</h2>
    <?php foreach ($pasos as $paso): ?>
      <div class="paso">
        <div class="paso-head">
          <strong><?= htmlspecialchars($paso['nombre']) ?></strong>
          <?php if ($paso['hecho']): ?>
            <span class="badge hecho">✓ hecho</span>
          <?php elseif (!$paso['archivo_ok']): ?>
            <span class="badge falta">falta archivo fuente</span>
          <?php else: ?>
            <span class="badge pendiente">pendiente</span>
          <?php endif; ?>
        </div>
        <div class="detalle"><?= htmlspecialchars($paso['detalle']) ?></div>
        <?php if (!$paso['archivo_ok'] && $paso['archivo']): ?>
          <div class="detalle">⚠ No se encontró: <code><?= htmlspecialchars($paso['archivo']) ?></code> — sube el archivo antes de correr este paso.</div>
        <?php endif; ?>
        <?php if ($paso['nota']): ?>
          <div class="nota"><?= htmlspecialchars($paso['nota']) ?></div>
        <?php endif; ?>

        <?php if ($paso['archivo_ok']): ?>
          <form method="post" onsubmit="return confirm('¿Correr <?= htmlspecialchars(addslashes($paso['nombre'])) ?> ahora? La página puede tardar en responder mientras corre.');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="accion" value="correr_paso">
            <input type="hidden" name="paso" value="<?= htmlspecialchars($paso['id']) ?>">
            <?php if ($paso['hecho'] && !$paso['idempotente']): ?>
              <label class="forzar"><input type="checkbox" name="forzar" value="1"> forzar de todos modos (puede duplicar datos)</label>
              <button type="submit" class="secondary">Volver a correr</button>
            <?php else: ?>
              <button type="submit"><?= $paso['hecho'] ? 'Volver a correr' : 'Ejecutar' ?></button>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>Crear API key</h2>
    <form method="post" style="display:flex; gap:8px;">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="accion" value="crear_key">
      <input type="text" name="proyecto" placeholder="Nombre del proyecto (ej. MeUnoColonia)" style="flex:1;" required>
      <button type="submit">Crear</button>
    </form>

    <?php if ($apiKeys): ?>
      <table style="margin-top:14px;">
        <thead><tr><th>Proyecto</th><th>Activa</th><th>Último uso</th><th>Creada</th></tr></thead>
        <tbody>
          <?php foreach ($apiKeys as $k): ?>
            <tr>
              <td><?= htmlspecialchars($k['proyecto']) ?></td>
              <td class="<?= $k['activa'] ? 'activa-si' : 'activa-no' ?>"><?= $k['activa'] ? 'sí' : 'no' ?></td>
              <td><?= htmlspecialchars($k['ultimo_uso'] ?? '—') ?></td>
              <td><?= htmlspecialchars($k['creado_en']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
