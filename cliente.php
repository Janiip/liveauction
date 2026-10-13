<?php
require_once __DIR__ . '/auth.php';
requireLogin('cliente');
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>LiveAuction | Cliente</title></head>
<body>
  <h1>Bienvenido, <?= htmlspecialchars($_SESSION['nombre']) ?> (Cliente)</h1>
  <p><a href="logout.php">Cerrar sesión</a></p>
  <!-- TODO: reemplazar por la vista real del panel cliente -->
</body>
</html>