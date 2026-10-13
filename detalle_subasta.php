<?php
session_start();
require_once "auth.php";
require_once "conexion.php";
require_once "subastas_lib.php";

// Solo clientes autenticados pueden ver esta página.
requireLogin('cliente');

try {
    $conexion = getDatabaseConnection();
} catch (Throwable $e) {
    die("No se pudo conectar a la base de datos.");
}

// Cierra las subastas cuyo tiempo ya terminó.
cerrarSubastasVencidas($conexion);

// Sin id válido se vuelve al listado.
$idSubasta = (int)($_GET['id'] ?? 0);
if ($idSubasta <= 0) {
    header('Location: c_obras_activas.php');
    exit;
}

// Datos de la subasta y de la obra (o.* trae también la descripción).
$stmt = $conexion->prepare("
    SELECT s.id_subasta,
           s.precio_actual,
           s.tiempo_extension,
           o.*,
           c.nombre AS categoria,
           GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), s.fecha_fin)) AS segundos_restantes
    FROM subastas s
    INNER JOIN obras o ON o.id_obra = s.id_obra
    INNER JOIN categorias c ON c.id = o.id_categoria
    WHERE s.id_subasta = ?
      AND s.estado = 'activa'
      AND s.fecha_fin > NOW()
    LIMIT 1
");
$stmt->bind_param('i', $idSubasta);
$stmt->execute();
$subasta = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Si no existe o ya terminó, se vuelve al listado.
if (!$subasta) {
    header('Location: c_obras_activas.php');
    exit;
}

// Imágenes de la obra (la principal primero).
$imagenes = [];
$stmt = $conexion->prepare("
    SELECT ruta_imagen
    FROM imagenes_obras
    WHERE id_obra = ?
    ORDER BY principal DESC, id_imagen ASC
");
$idObra = (int)$subasta['id_obra'];
$stmt->bind_param('i', $idObra);
$stmt->execute();
$resImg = $stmt->get_result();
while ($fila = $resImg->fetch_assoc()) {
    $imagenes[] = $fila['ruta_imagen'];
}
$stmt->close();

$precio = $subasta['precio_actual'];
if ($precio === null) {
    $precio = $subasta['precio_base'];
}
$segundos    = (int)$subasta['segundos_restantes'];
$descripcion = trim((string)($subasta['descripcion'] ?? ''));

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function dinero($valor) {
    return "$" . number_format((float)$valor, 0, ",", ".");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LiveAuction - <?= e($subasta['titulo']) ?></title>
    <link rel="stylesheet" href="css/c_obras_activas.css">
    <link rel="stylesheet" href="css/detalle_subasta.css">
</head>
<body>

<header class="topbar">
    <div class="brand">
        <div class="brand-mark">LA</div>
        <div class="brand-copy">
            <h1>LiveAuction</h1>
            <span>Cliente</span>
        </div>
    </div>

    <div class="user-actions">
        <button class="round-action" type="button"
                aria-label="Notificaciones" title="Notificaciones">
            ♧
        </button>

        <div class="avatar">
            <?php
            if (isset($_SESSION["nombre"])) {
                $partes = preg_split('/\s+/', trim($_SESSION["nombre"]));
                $iniciales = $partes[0][0] ?? '';
                if (isset($partes[1])) {
                    $iniciales .= $partes[1][0];
                }
                echo e(strtoupper($iniciales));
            } else {
                echo "JG";
            }
            ?>
        </div>

        <a class="round-action" href="logout.php"
           aria-label="Cerrar sesión" title="Cerrar sesión">
            ⇥
        </a>
    </div>
</header>

<nav class="tabs">
    <a class="tab" href="c_obras_activas.php">Subastas activas</a>
    <a class="tab active" href="detalle_subasta.php?id=<?= (int)$subasta['id_subasta'] ?>">Detalle subasta</a>
    <a class="tab" href="mis_pujas.php">Mis pujas</a>
    <a class="tab" href="notificaciones.php">Notificaciones</a>
</nav>

<main class="page">
    <a class="back-link" href="c_obras_activas.php">‹ Volver a subastas activas</a>

    <section class="detail">

        <!-- Galería de imágenes -->
        <div class="gallery">
            <div class="gallery-main">
                <?php if (count($imagenes) > 0): ?>
                    <img id="main-image"
                         src="<?= e($imagenes[0]) ?>"
                         alt="<?= e($subasta['titulo']) ?>">
                <?php else: ?>
                    <span class="picture-icon">▧</span>
                <?php endif; ?>
            </div>

            <?php if (count($imagenes) > 1): ?>
                <div class="gallery-thumbs">
                    <?php foreach ($imagenes as $i => $ruta): ?>
                        <button type="button"
                                class="thumb<?= $i === 0 ? ' active' : '' ?>"
                                data-src="<?= e($ruta) ?>"
                                aria-label="Ver imagen <?= $i + 1 ?>">
                            <img src="<?= e($ruta) ?>" alt="">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Información y puja -->
        <div class="info">
            <span class="category-tag"><?= e($subasta['categoria']) ?></span>
            <h2><?= e($subasta['titulo']) ?></h2>
            <p class="artist">por <?= e($subasta['artista']) ?></p>

            <?php if ($descripcion !== ''): ?>
                <p class="description"><?= nl2br(e($descripcion)) ?></p>
            <?php endif; ?>

            <div class="price-box">
                <div class="price-block">
                    <span class="label">Oferta actual</span>
                    <strong class="price"><?= dinero($precio) ?></strong>
                </div>
                <div class="price-block time-block">
                    <span class="label">Tiempo restante</span>
                    <strong class="time js-cronometro"
                            data-id="<?= (int)$subasta['id_subasta'] ?>"
                            data-segundos="<?= $segundos ?>">--:--</strong>
                </div>
            </div>

            <p class="base-price">
                Precio base: <?= dinero($subasta['precio_base']) ?>
            </p>

            <!-- TODO (tarea "Formulario de puja"): agregar action, método POST,
                 validación del monto mínimo y envío real de la puja. -->
            <form class="bid-form" onsubmit="return false;">
                <label for="monto">Tu puja</label>
                <div class="bid-row">
                    <span class="currency">$</span>
                    <input type="number" id="monto" name="monto"
                           min="<?= (int)$precio + 1 ?>" step="1"
                           placeholder="<?= (int)$precio + 1 ?>">
                    <button type="button" class="bid-button">Pujar</button>
                </div>
                <p class="bid-help">
                    Ingresá un monto mayor a la oferta actual.
                    Si pujás en los últimos <?= (int)$subasta['tiempo_extension'] ?> segundos,
                    el cronómetro se extiende.
                </p>
            </form>
        </div>
    </section>

    <!-- Historial de pujas -->
    <section class="history">
        <h3>Historial de pujas</h3>

        <!-- TODO (tarea "Detalle de subasta con historial de pujas"):
             reemplazar las filas de ejemplo por las pujas de la base de datos.
             Mantener la estructura de .history-row (la primera lleva .leader). -->
        <div class="history-list">
            <div class="history-row leader">
                <span class="bidder">Cliente A <em class="badge">Mejor oferta</em></span>
                <span class="bid-amount"><?= dinero($precio) ?></span>
                <span class="bid-date">14:32:10</span>
            </div>
            <div class="history-row">
                <span class="bidder">Cliente B</span>
                <span class="bid-amount"><?= dinero($precio * 0.95) ?></span>
                <span class="bid-date">14:30:41</span>
            </div>
            <div class="history-row">
                <span class="bidder">Cliente C</span>
                <span class="bid-amount"><?= dinero($precio * 0.9) ?></span>
                <span class="bid-date">14:27:05</span>
            </div>
        </div>

        <p class="empty-state" hidden>Todavía no hay pujas. ¡Sé el primero!</p>
    </section>
</main>

<script>
// Cambia la imagen principal al hacer clic en una miniatura.
const mainImage = document.getElementById("main-image");
document.querySelectorAll(".thumb").forEach(thumb => {
    thumb.addEventListener("click", () => {
        mainImage.src = thumb.dataset.src;
        document.querySelectorAll(".thumb")
            .forEach(t => t.classList.remove("active"));
        thumb.classList.add("active");
    });
});
</script>
<script src="js/cronometro.js"></script>

</body>
</html>