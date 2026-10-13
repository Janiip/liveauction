<?php
/**
 * Devuelve en JSON el tiempo restante de una subasta (fecha_fin viene de la BD).
 * Uso: api_subasta_tiempo.php?id=3
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/subastas_lib.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);

try {
    $db = getDatabaseConnection();
    cerrarSubastasVencidas($db);
    $datos = obtenerTiempoSubasta($db, $id);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos']);
    exit;
}

if (!$datos) {
    http_response_code(404);
    echo json_encode(['error' => 'Subasta no encontrada']);
    exit;
}

echo json_encode([
    'id_subasta'         => (int)$datos['id_subasta'],
    'estado'             => $datos['estado'],
    'fecha_fin'          => $datos['fecha_fin'],
    'precio_actual'      => $datos['precio_actual'] !== null ? (float)$datos['precio_actual'] : null,
    'segundos_restantes' => (int)$datos['segundos_restantes'],
]);
