<?php
/**
 * LiveAuction - Login (index.php)
 * Valida email + contraseña + rol contra la tabla `usuarios`.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$email_valor = '';
$rol_valor = 'cliente';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rol_seleccionado = $_POST['rol'] ?? '';

    $email_valor = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $rol_valor = $rol_seleccionado;

    if ($email === '' || $password === '' || $rol_seleccionado === '') {
        $error = 'Completá todos los campos.';
    } else {
        try {
            $mysqli = getDatabaseConnection();

            $stmt = $mysqli->prepare(
                'SELECT id, nombre, apellido, password, rol, estado
                 FROM usuarios WHERE email = ? LIMIT 1'
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $usuario = $resultado->fetch_assoc();
            $stmt->close();

            if (!$usuario || !password_verify($password, $usuario['password'])) {
                $error = 'Email o contraseña incorrectos.';
            } elseif ($usuario['estado'] === 'suspendido') {
                $error = 'Tu cuenta se encuentra suspendida.';
            } elseif ($usuario['rol'] !== $rol_seleccionado) {
                $error = 'El rol seleccionado no coincide con el de tu cuenta.';
            } else {
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['nombre'] = $usuario['nombre'] . ' ' . $usuario['apellido'];
                $_SESSION['rol'] = $usuario['rol'];

                redirectPorRol($usuario['rol']);
            }
        } catch (Throwable $e) {
            $error = 'No se pudo conectar a la base de datos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LiveAuction | Ingresar</title>
<link rel="stylesheet" href="css/index.css">
</head>
<body>

<div class="login-wrapper">

  <aside class="panel-brand">
    <div class="brand-logo">LA</div>

    <h1 class="brand-title">LiveAuction</h1>
    <p class="brand-subtitle">
      Subastas de obras de arte en tiempo real, directo entre artistas y coleccionistas.
    </p>

    <ul class="brand-features">
      <li>
        <span class="feature-num">I.</span>
        <div>
          <strong>Cronómetro en tiempo real</strong>
          <p>Cada lote se cierra al segundo indicado</p>
        </div>
      </li>
      <li>
        <span class="feature-num">II.</span>
        <div>
          <strong>Alertas instantáneas</strong>
          <p>Te avisamos si superan tu oferta</p>
        </div>
      </li>
      <li>
        <span class="feature-num">III.</span>
        <div>
          <strong>Pujas verificadas</strong>
          <p>Historial y ganador quedan registrados</p>
        </div>
      </li>
    </ul>
  </aside>

  <main class="panel-form">
    <div class="form-box">
      <h2>Ingresar</h2>
      <p class="form-subtitle">Usá tu cuenta para continuar</p>

      <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form id="login-form" method="POST" action="index.php" novalidate>

        <label for="email">Email</label>
        <input
          type="email"
          id="email"
          name="email"
          placeholder="usuario@mail.com"
          value="<?= $email_valor ?>"
          required
        >

        <label for="password">Contraseña</label>
        <input
          type="password"
          id="password"
          name="password"
          placeholder="••••••••"
          required
        >

        <label for="rol">Rol</label>
        <select id="rol" name="rol" required>
          <option value="cliente"       <?= $rol_valor === 'cliente' ? 'selected' : '' ?>>Cliente</option>
          <option value="moderador"     <?= $rol_valor === 'moderador' ? 'selected' : '' ?>>Moderador</option>
          <option value="administrador" <?= $rol_valor === 'administrador' ? 'selected' : '' ?>>Admin</option>
        </select>

        <span id="form-error" class="field-error"></span>

        <button type="submit" class="btn-ingresar">Ingresar</button>
      </form>

      <p class="register-link">
        ¿No tenés cuenta? <a href="registro.php">Registrate</a>
      </p>
    </div>
  </main>

</div>

<script src="js/index.js"></script>
</body>
</html>