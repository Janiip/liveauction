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

// Token CSRF simple
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// --- Envío de una puja (patrón POST -> redirect -> GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['error', 'Solicitud inválida. Recargá la página.'];
    } else {
        $montoIngresado = str_replace(',', '.', trim((string)($_POST['monto'] ?? '')));
        if (!is_numeric($montoIngresado)) {
            $_SESSION['flash'] = ['error', 'Ingresá un monto válido.'];
        } else {
            [$okPuja, $msgPuja] = registrarPuja(
                $conexion,
                $idSubasta,
                (int)$_SESSION['usuario_id'],
                (float)$montoIngresado
            );
            $_SESSION['flash'] = [$okPuja ? 'ok' : 'error', $msgPuja];
        }
    }
    header('Location: detalle_subasta.php?id=' . $idSubasta);
    exit;
}

// Mensaje de la última acción (si hubo)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Datos de la subasta y de la obra (o.* trae también la descripción).
$stmt = $conexion->prepare("
    SELECT s.id_subasta,
           s.precio_actual,
           s.id_mejor_postor,
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
$minimo      = montoMinimoPuja($subasta['precio_actual'], $subasta['precio_base']);
$sinPujas    = $subasta['precio_actual'] === null;
$soyLider    = $subasta['id_mejor_postor'] !== null
               && (int)$subasta['id_mejor_postor'] === (int)$_SESSION['usuario_id'];
$historial   = obtenerHistorialPujas($conexion, $idSubasta);
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
    <style>
        .flash { padding: 11px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 13px; }
        .flash-ok { background: #e6f3e8; color: #1f5d2b; border: 1px solid #b7dcbe; }
        .flash-error { background: #fbeaea; color: #b93c2d; border: 1px solid #efc2c0; }
        .bid-leader { background: #f3ecd9; color: #7a5a14; border: 1px solid #e3d3a6; }
        .bid-button:disabled, .bid-row input:disabled { opacity: .55; cursor: not-allowed; }
    </style>
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

            <?php if ($flash): ?>
                <div class="flash <?= $flash[0] === 'ok' ? 'flash-ok' : 'flash-error' ?>">
                    <?= e($flash[1]) ?>
                </div>
            <?php endif; ?>

            <?php if ($soyLider): ?>
                <div class="flash bid-leader">Sos el mejor postor. Si alguien te supera, vas a poder pujar de nuevo.</div>
            <?php endif; ?>

            <form class="bid-form" id="bid-form" method="POST"
                  action="detalle_subasta.php?id=<?= (int)$subasta['id_subasta'] ?>">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <label for="monto">Tu puja</label>
                <div class="bid-row">
                    <span class="currency">$</span>
                    <input type="number" id="monto" name="monto"
                           min="<?= (int)ceil($minimo) ?>" step="1" required
                           placeholder="<?= (int)ceil($minimo) ?>"
                           <?= $soyLider ? 'disabled' : '' ?>>
                    <button type="submit" class="bid-button" <?= $soyLider ? 'disabled' : '' ?>>Pujar</button>
                </div>
                <p class="bid-help" id="bid-error" style="color:#b93c2d" hidden></p>
                <p class="bid-help">
                    Monto mínimo: <strong><?= dinero($minimo) ?></strong>
                    <?= $sinPujas ? '(precio base: todavía no hay pujas)' : '(oferta actual + $1)' ?>.
                    Si pujás en los últimos <?= (int)$subasta['tiempo_extension'] ?> segundos,
                    el cronómetro se extiende.
                </p>
            </form>
        </div>
    </section>

    <!-- Historial de pujas -->
    <section class="history">
        <h3>Historial de pujas</h3>

        <?php if (count($historial) === 0): ?>
            <p class="empty-state">Todavía no hay pujas. ¡Sé el primero!</p>
        <?php else: ?>
            <div class="history-list">
                <?php foreach ($historial as $i => $p):
                    $esMia = (int)$p['id_cliente'] === (int)$_SESSION['usuario_id'];
                    // Se muestra nombre + inicial del apellido (privacidad)
                    $inicial = mb_substr(trim($p['apellido']), 0, 1, 'UTF-8');
                    $nombrePuja = $p['nombre'] . ($inicial !== '' ? ' ' . $inicial . '.' : '');
                ?>
                    <div class="history-row<?= $i === 0 ? ' leader' : '' ?>">
                        <span class="bidder">
                            <?= e($nombrePuja) ?><?= $esMia ? ' (vos)' : '' ?>
                            <?php if ($i === 0): ?><em class="badge">Mejor oferta</em><?php endif; ?>
                        </span>
                        <span class="bid-amount"><?= dinero($p['monto']) ?></span>
                        <span class="bid-date"><?= e($p['hora']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
<script>
// Validación del monto mínimo antes de enviar (el servidor vuelve a validar).
const bidForm = document.getElementById("bid-form");
const bidError = document.getElementById("bid-error");
const MONTO_MINIMO = <?= (int)ceil($minimo) ?>;
bidForm.addEventListener("submit", e => {
    const monto = parseFloat(document.getElementById("monto").value);
    if (isNaN(monto) || monto < MONTO_MINIMO) {
        e.preventDefault();
        bidError.textContent = "El monto mínimo es $" + MONTO_MINIMO.toLocaleString("es-AR") + ".";
        bidError.hidden = false;
        return;
    }
    bidForm.querySelector(".bid-button").disabled = true; // evita doble envío
});

// Cuando el cronómetro llega a 0, recargar (la subasta pasa a cerrada).
document.addEventListener("cronometro:fin", () => {
    setTimeout(() => location.reload(), 1500);
});
</script>
<script src="js/cronometro.js"></script>

</body>
</html>