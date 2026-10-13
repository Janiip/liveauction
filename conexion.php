
<?php

$host = "localhost";
$usuario = "root";
$password = "";
$base_datos = "liveauction";

// Crear conexión con MySQL/MariaDB
$conexion = new mysqli(
    $host,
    $usuario,
    $password,
    $base_datos
);

// Comprobar la conexión
if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

// Configurar caracteres para admitir tildes y ñ
$conexion->set_charset("utf8mb4");

?>