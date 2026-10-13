<?php
/**
 * LiveAuction - Gestión de subastas (abrir / cerrar)
 * Acceso: moderador y administrador.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/subastas_lib.php';

requireRoles(['moderador', 'administrador']);

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Token CSRF simple
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$volver = $_SESSION['rol'] === 'administrador' ? 'admin.php' : 'moderador.php';
$mensaje = '';
$tipoMensaje = 'ok';

try {
    $db = getDatabaseConnection();
} catch (Throwable $ex) {
    die('No se pudo conectar a la base de datos.');
}

// Cierra las vencidas antes de mostrar nada
cerrarSubastasVencidas($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $mensaje = 'Solicitud inválida. Recargá la página.';
        $tipoMensaje = 'error';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'crear' && $_SESSION['rol'] === 'administrador') {
            [$ok, $mensaje] = crearSubasta(
                $db,
                (int)($_POST['id_obra'] ?? 0),
                (int)($_POST['duracion'] ?? 0),
                (int)($_POST['extension'] ?? 0),
                (int)$_SESSION['usuario_id']
            );
        } elseif ($accion === 'abrir') {
            [$ok, $mensaje] = abrirSubasta($db, (int)($_POST['id_subasta'] ?? 0), (int)$_SESSION['usuario_id']);
        } elseif ($accion === 'cerrar') {
            [$ok, $mensaje] = cerrarSubasta($db, (int)($_POST['id_subasta'] ?? 0), (int)$_SESSION['usuario_id']);
        } else {
            $ok = false;
            $mensaje = 'Acción desconocida.';
        }
        $tipoMensaje = $ok ? 'ok' : 'error';
    }
}

// Obras para las que el admin puede crear una subasta (misma regla que crearSubasta)
$obrasLibres = $db->query("
    SELECT o.id_obra, o.titulo, o.artista, o.precio_base
    FROM obras o
    LEFT JOIN subastas s ON s.id_obra = o.id_obra
    WHERE o.estado = 'pendiente'
      AND (s.id_subasta IS NULL
           OR (s.estado = 'cerrada'
               AND NOT EXISTS (SELECT 1 FROM pujas p WHERE p.id_subasta = s.id_subasta)))
    ORDER BY o.titulo
")->fetch_all(MYSQLI_ASSOC);

// Subastas programadas (listas para abrir)
$programadas = $db->query("
    SELECT s.id_subasta, s.tiempo_extension, o.titulo, o.artista, o.precio_base,
           TIMESTAMPDIFF(MINUTE, s.fecha_inicio, s.fecha_fin) AS duracion_min
    FROM subastas s
    INNER JOIN obras o ON o.id_obra = s.id_obra
    WHERE s.estado = 'programada'
    ORDER BY o.titulo
")->fetch_all(MYSQLI_ASSOC);

// Subastas activas
$activas = $db->query("
    SELECT s.id_subasta, s.fecha_fin, s.precio_actual, s.tiempo_extension, o.titulo, o.artista, o.precio_base,
           CONCAT(u.nombre, ' ', u.apellido) AS mejor_postor,
           GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), s.fecha_fin)) AS segundos
    FROM subastas s
    INNER JOIN obras o ON o.id_obra = s.id_obra
    LEFT JOIN usuarios u ON u.id = s.id_mejor_postor
    WHERE s.estado = 'activa'
    ORDER BY s.fecha_fin ASC
")->fetch_all(MYSQLI_ASSOC);

// Últimas cerradas
$cerradas = $db->query("
    SELECT s.id_subasta, s.fecha_fin, s.precio_actual, o.titulo, o.artista,
           CONCAT(u.nombre, ' ', u.apellido) AS mejor_postor
    FROM subastas s
    INNER JOIN obras o ON o.id_obra = s.id_obra
    LEFT JOIN usuarios u ON u.id = s.id_mejor_postor
    WHERE s.estado = 'cerrada'
    ORDER BY s.fecha_fin DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LiveAuction | Gestión de subastas</title>
<link rel="stylesheet" href="css/gestion_subastas.css">
</head>
<body>

<header class="topbar">
  <div class="brand"><div class="brand-mark">LA</div><h1>LiveAuction</h1></div>
  <div class="user">
    <span><?= e($_SESSION['nombre']) ?> (<?= e($_SESSION['rol']) ?>)</span>
    <a href="<?= e($volver) ?>">Volver al panel</a>
    <a href="logout.php">Cerrar sesión</a>
  </div>
</header>

<main class="page">
  <h2>Gestión de subastas</h2>

  <?php if ($mensaje): ?>
    <div class="alert <?= $tipoMensaje === 'ok' ? 'alert-ok' : 'alert-error' ?>"><?= e($mensaje) ?></div>
  <?php endif; ?>

  <?php if ($_SESSION['rol'] === 'administrador'): ?>
  <!-- CREAR (solo admin) -->
  <section class="card">
    <h3>Crear subasta</h3>
    <?php if (count($obrasLibres) === 0): ?>
      <p class="muted">No hay obras pendientes disponibles para crear una subasta.</p>
    <?php else: ?>
      <form method="POST" class="form-inline">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="accion" value="crear">

        <label>Obra
          <select name="id_obra" required>
            <option value="">Seleccionar...</option>
            <?php foreach ($obrasLibres as $o): ?>
              <option value="<?= (int)$o['id_obra'] ?>">
                <?= e($o['titulo']) ?> — <?= e($o['artista']) ?> ($<?= number_format((float)$o['precio_base'], 0, ',', '.') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Duración (minutos)
          <input type="number" name="duracion" min="1" max="43200" value="60" required>
        </label>

        <label>Extensión al pujar (seg)
          <input type="number" name="extension" min="0" max="600" value="10" required>
        </label>

        <button type="submit" class="btn btn-gold">Crear subasta</button>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- PROGRAMADAS -->
  <section class="card">
    <h3>Subastas programadas (<?= count($programadas) ?>)</h3>
    <?php if (count($programadas) === 0): ?>
      <p class="muted">No hay subastas programadas para abrir.<?= $_SESSION['rol'] === 'moderador' ? ' El administrador es quien las crea.' : '' ?></p>
    <?php else: ?>
      <table>
        <thead><tr><th>Obra</th><th>Precio base</th><th>Duración</th><th>Extensión</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($programadas as $s): ?>
          <tr>
            <td><strong><?= e($s['titulo']) ?></strong><br><small><?= e($s['artista']) ?></small></td>
            <td>$<?= number_format((float)$s['precio_base'], 0, ',', '.') ?></td>
            <td><?= (int)$s['duracion_min'] ?> min</td>
            <td>+<?= (int)$s['tiempo_extension'] ?> s</td>
            <td>
              <form method="POST">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="accion" value="abrir">
                <input type="hidden" name="id_subasta" value="<?= (int)$s['id_subasta'] ?>">
                <button type="submit" class="btn btn-gold">Abrir</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <!-- ACTIVAS -->
  <section class="card">
    <h3>Subastas activas (<?= count($activas) ?>)</h3>
    <?php if (count($activas) === 0): ?>
      <p class="muted">No hay subastas activas.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Obra</th><th>Oferta actual</th><th>Mejor postor</th><th>Cierra</th><th>Tiempo restante</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($activas as $s):
            $precio = $s['precio_actual'] ?? $s['precio_base']; ?>
          <tr>
            <td><strong><?= e($s['titulo']) ?></strong><br><small><?= e($s['artista']) ?></small></td>
            <td>$<?= number_format((float)$precio, 0, ',', '.') ?></td>
            <td><?= $s['mejor_postor'] ? e($s['mejor_postor']) : '—' ?></td>
            <td><?= e($s['fecha_fin']) ?><br><small>+<?= (int)$s['tiempo_extension'] ?> s al pujar</small></td>
            <td>
              <span class="js-cronometro" data-id="<?= (int)$s['id_subasta'] ?>"
                    data-segundos="<?= (int)$s['segundos'] ?>">--:--</span>
            </td>
            <td>
              <form method="POST" onsubmit="return confirm('¿Cerrar esta subasta ahora?');">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="accion" value="cerrar">
                <input type="hidden" name="id_subasta" value="<?= (int)$s['id_subasta'] ?>">
                <button type="submit" class="btn btn-danger">Cerrar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <!-- CERRADAS -->
  <section class="card">
    <h3>Últimas subastas cerradas</h3>
    <?php if (count($cerradas) === 0): ?>
      <p class="muted">Todavía no hay subastas cerradas.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Obra</th><th>Oferta final</th><th>Ganador</th><th>Cerró</th></tr></thead>
        <tbody>
        <?php foreach ($cerradas as $s): ?>
          <tr>
            <td><strong><?= e($s['titulo']) ?></strong><br><small><?= e($s['artista']) ?></small></td>
            <td><?= $s['precio_actual'] !== null ? '$' . number_format((float)$s['precio_actual'], 0, ',', '.') : 'Sin pujas' ?></td>
            <td><?= $s['mejor_postor'] ? e($s['mejor_postor']) : '—' ?></td>
            <td><?= e($s['fecha_fin']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</main>

<script src="js/cronometro.js"></script>
<script>
// Cuando un cronómetro llega a 0, recargar para que pase a "cerradas"
document.addEventListener('cronometro:fin', function () {
  setTimeout(function () { location.reload(); }, 1500);
});
</script>
</body>
</html>