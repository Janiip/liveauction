<?php
require_once __DIR__ . '/auth.php';

cerrarSesion();
header('Location: index.php');
exit;