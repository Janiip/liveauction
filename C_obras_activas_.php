
<?php
session_start();
require_once "conexion.php";

// Obtener las categorías registradas.
$categorias = [];

$sqlCategorias = "SELECT id, nombre FROM categorias ORDER BY nombre";
$resultadoCategorias = $conexion->query($sqlCategorias);

if ($resultadoCategorias) {
    while ($fila = $resultadoCategorias->fetch_assoc()) {
        $categorias[] = $fila;
    }
}

// Obtener solamente las subastas activas y vigentes.
$sql = "
    SELECT
        s.id_subasta,
        s.fecha_fin,
        s.precio_actual,
        o.id_obra,
        o.titulo,
        o.artista,
        o.precio_base,
        c.id AS id_categoria,
        c.nombre AS categoria,
        (
            SELECT io.ruta_imagen
            FROM imagenes_obras io
            WHERE io.id_obra = o.id_obra
            ORDER BY io.principal DESC, io.id_imagen ASC
            LIMIT 1
        ) AS imagen,
        TIMESTAMPDIFF(
            SECOND, NOW(), s.fecha_fin
        ) AS segundos_restantes
    FROM subastas s
    INNER JOIN obras o ON o.id_obra = s.id_obra
    INNER JOIN categorias c ON c.id = o.id_categoria
    WHERE s.estado = 'activa'
      AND o.estado = 'activa'
      AND s.fecha_inicio <= NOW()
      AND s.fecha_fin > NOW()
    ORDER BY COALESCE(s.precio_actual, o.precio_base) DESC
";

$resultado = $conexion->query($sql);
$subastas = [];

if ($resultado) {
    while ($fila = $resultado->fetch_assoc()) {
        $subastas[] = $fila;
    }
}

function e($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        "UTF-8"
    );
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
    <title>LiveAuction - Subastas activas</title>
    <link rel="stylesheet" href="c_obras_activas.css">
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
                echo e(strtoupper(
                    substr($_SESSION["nombre"], 0, 2)
                ));
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
    <a class="tab active" href="index.php">Subastas activas</a>
    <a class="tab" href="detalle_subasta.php">Detalle subasta</a>
    <a class="tab" href="mis_pujas.php">Mis pujas</a>
    <a class="tab" href="notificaciones.php">Notificaciones</a>
</nav>

<main class="page">
    <section class="heading">
        <h2>Subastas activas</h2>
        <p>Obras disponibles para pujar ahora</p>
    </section>

    <section class="filters">
        <label class="search-box">
            <span class="search-icon">⌕</span>
            <input type="search" id="search"
                   placeholder="Buscar obra o artista..."
                   aria-label="Buscar obra o artista">
        </label>

        <select id="category" aria-label="Filtrar categoría">
            <option value="todas">Todas las categorías</option>
            <?php foreach ($categorias as $categoria): ?>
                <option value="<?= (int)$categoria['id'] ?>">
                    <?= e($categoria['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select id="sort" aria-label="Ordenar subastas">
            <option value="high">Mayor precio</option>
            <option value="low">Menor precio</option>
            <option value="ending">Finaliza primero</option>
        </select>
    </section>

    <section class="auction-list" id="auction-list">

        <?php if (count($subastas) === 0): ?>
            <p class="empty-state" id="no-auctions">
                No hay subastas activas disponibles en este momento.
            </p>
        <?php endif; ?>

        <?php foreach ($subastas as $indice => $subasta): ?>
            <?php
            $precio = $subasta["precio_actual"];

            if ($precio === null) {
                $precio = $subasta["precio_base"];
            }

            $segundos = max(
                0,
                (int)$subasta["segundos_restantes"]
            );

            $imagen = $subasta["imagen"] ?? "";
            ?>

            <article class="auction-row"
                data-title="<?= e($subasta['titulo']) ?>"
                data-artist="<?= e($subasta['artista']) ?>"
                data-category="<?= (int)$subasta['id_categoria'] ?>"
                data-price="<?= (float)$precio ?>"
                data-time="<?= $segundos ?>">

                <span class="number">
                    <?= str_pad($indice + 1, 2, "0", STR_PAD_LEFT) ?>
                </span>

                <div class="art-placeholder">
                    <?php if ($imagen !== ""): ?>
                        <img class="art-image"
                             src="<?= e($imagen) ?>"
                             alt="<?= e($subasta['titulo']) ?>">
                    <?php else: ?>
                        <span class="picture-icon">▧</span>
                    <?php endif; ?>
                </div>

                <div class="art-info">
                    <h3><?= e($subasta['titulo']) ?></h3>
                    <p>
                        <?= e($subasta['artista']) ?>,
                        <?= e($subasta['categoria']) ?>
                    </p>
                </div>

                <div class="bid-info">
                    <span>Oferta actual</span>
                    <strong><?= dinero($precio) ?></strong>
                </div>

                <div class="time"
                     data-countdown="<?= $segundos ?>">
                    --:--
                </div>

                <a class="row-arrow"
                   href="detalle_subasta.php?id=<?= (int)$subasta['id_subasta'] ?>"
                   aria-label="Ver detalle de la subasta">
                    ›
                </a>
            </article>
        <?php endforeach; ?>

        <p class="empty-state" id="no-results" hidden>
            No se encontraron obras con esos filtros.
        </p>
    </section>
</main>

<script>
const search = document.getElementById("search");
const category = document.getElementById("category");
const sort = document.getElementById("sort");
const list = document.getElementById("auction-list");
const noResults = document.getElementById("no-results");

const rows = Array.from(
    document.querySelectorAll(".auction-row")
);

// Buscar, filtrar y ordenar las subastas cargadas desde PHP.
function updateAuctions() {
    const term = search.value.trim().toLocaleLowerCase("es");
    const selectedCategory = category.value;

    rows.forEach(row => {
        const title = row.dataset.title.toLocaleLowerCase("es");
        const artist = row.dataset.artist.toLocaleLowerCase("es");

        const matchesText =
            title.includes(term) || artist.includes(term);

        const matchesCategory =
            selectedCategory === "todas" ||
            row.dataset.category === selectedCategory;

        row.hidden = !(matchesText && matchesCategory);
    });

    const visibles = rows.filter(row => !row.hidden);

    visibles.sort((a, b) => {
        const precioA = Number(a.dataset.price);
        const precioB = Number(b.dataset.price);
        const tiempoA = Number(a.dataset.time);
        const tiempoB = Number(b.dataset.time);

        if (sort.value === "low") return precioA - precioB;
        if (sort.value === "ending") return tiempoA - tiempoB;
        return precioB - precioA;
    });

    visibles.forEach(row => list.insertBefore(row, noResults));

    noResults.hidden = visibles.length !== 0;
}

search.addEventListener("input", updateAuctions);
category.addEventListener("change", updateAuctions);
sort.addEventListener("change", updateAuctions);

// Cuenta regresiva visual.
document.querySelectorAll("[data-countdown]").forEach(timer => {
    let segundos = Number(timer.dataset.countdown);

    function actualizar() {
        if (segundos <= 0) {
            timer.textContent = "Finalizada";
            timer.classList.add("urgent");
            return;
        }

        const dias = Math.floor(segundos / 86400);
        const horas = Math.floor((segundos % 86400) / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        const seg = segundos % 60;

        if (dias > 0) {
            timer.textContent = `${dias}d ${String(horas).padStart(2, "0")}h`;
        } else {
            timer.textContent =
                `${String(horas).padStart(2, "0")}:` +
                `${String(minutos).padStart(2, "0")}:` +
                `${String(seg).padStart(2, "0")}`;
        }

        if (segundos <= 300) timer.classList.add("urgent");
        segundos--;
    }

    actualizar();
    setInterval(actualizar, 1000);
});
</script>

</body>
</html>
