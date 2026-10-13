<?php
require_once __DIR__ . '/auth.php';
requireLogin('moderador');
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>LiveAuction | Moderador</title></head>
<body>
  <h1>Bienvenido, <?= htmlspecialchars($_SESSION['nombre']) ?> (Moderador)</h1>
  <p><a href="logout.php">Cerrar sesión</a></p>
  <!-- TODO: reemplazar por la vista real del panel moderador -->
</body>
</html>