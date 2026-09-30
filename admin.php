<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'administrador') {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>LiveAuction | Admin</title></head>
<body>
  <h1>Bienvenido, <?= htmlspecialchars($_SESSION['nombre']) ?> (Administrador)</h1>
  <p><a href="logout.php">Cerrar sesión</a></p>
  <!-- TODO: reemplazar por la vista real del panel administrador -->
</body>
</html>