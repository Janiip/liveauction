<?php
/**
 * LiveAuction - Lógica de subastas (crear, abrir, cerrar, cronómetro y extensión)
 *
 * - Todas las fechas se calculan con NOW() de MySQL (no con date() de PHP)
 *   para evitar diferencias de zona horaria entre PHP y la base.
 * - La tabla `subastas` tiene UNIQUE(id_obra): una obra tiene UNA sola fila de
 *   subasta. Por eso "crear" inserta la fila o reutiliza una cerrada sin pujas.
 * - Compatible con PHP 7.1 / MariaDB 10.1.
 */

/**
 * Registra una acción en la tabla `auditoria`.
 */
function registrarAuditoria(mysqli $db, int $idUsuario, string $accion, int $idSubasta): void {
    $stmt = $db->prepare("INSERT INTO auditoria
                          (id_usuario, accion, entidad_afectada, id_entidad_afectada, fecha)
                          VALUES (?, ?, 'subasta', ?, NOW())");
    $stmt->bind_param('isi', $idUsuario, $accion, $idSubasta);
    $stmt->execute();
    $stmt->close();
}

/**
 * Cierra todas las subastas activas cuyo fecha_fin ya pasó.
 * Si hubo ganador la obra pasa a 'cerrada'; si no hubo pujas vuelve a 'pendiente'
 * (así se puede volver a subastar).
 * Se llama al cargar las pantallas (no hace falta cron).
 */
function cerrarSubastasVencidas(mysqli $db): int {
    $db->query("UPDATE subastas s
                INNER JOIN obras o ON o.id_obra = s.id_obra
                SET s.estado = 'cerrada',
                    o.estado = IF(s.id_mejor_postor IS NULL, 'pendiente', 'cerrada')
                WHERE s.estado = 'activa' AND s.fecha_fin <= NOW()");
    return $db->affected_rows > 0 ? (int)($db->affected_rows / 2) : 0;
}

/**
 * CREAR subasta (RF05 - solo administrador). Queda en estado 'programada'.
 * La duración se guarda como diferencia fecha_fin - fecha_inicio y se aplica
 * recién cuando la subasta se abre.
 *
 * @param int $extensionSeg segundos de extensión (el proyecto define 10)
 */
function crearSubasta(mysqli $db, int $idObra, int $duracionMin, int $extensionSeg, int $idUsuario): array {
    if ($idObra <= 0) {
        return [false, 'Seleccioná una obra.'];
    }
    if ($duracionMin < 1 || $duracionMin > 60 * 24 * 30) {
        return [false, 'La duración debe estar entre 1 minuto y 30 días.'];
    }
    if ($extensionSeg < 0 || $extensionSeg > 600) {
        return [false, 'La extensión debe estar entre 0 y 600 segundos.'];
    }

    // Obra pendiente, sin subasta (UNIQUE id_obra) o con una cerrada sin pujas.
    $stmt = $db->prepare("
        SELECT o.id_obra, s.id_subasta
        FROM obras o
        LEFT JOIN subastas s ON s.id_obra = o.id_obra
        WHERE o.id_obra = ?
          AND o.estado = 'pendiente'
          AND (s.id_subasta IS NULL
               OR (s.estado = 'cerrada'
                   AND NOT EXISTS (SELECT 1 FROM pujas p WHERE p.id_subasta = s.id_subasta)))");
    $stmt->bind_param('i', $idObra);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fila) {
        return [false, 'La obra no está disponible (no está pendiente o ya tiene una subasta).'];
    }

    $db->begin_transaction();
    try {
        if ($fila['id_subasta'] === null) {
            $stmt = $db->prepare("INSERT INTO subastas
                (id_obra, fecha_inicio, fecha_fin, tiempo_extension, estado)
                VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, 'programada')");
            $stmt->bind_param('iii', $idObra, $duracionMin, $extensionSeg);
            $stmt->execute();
            $idSubasta = (int)$db->insert_id;
        } else {
            $idSubasta = (int)$fila['id_subasta'];
            $stmt = $db->prepare("UPDATE subastas
                SET fecha_inicio = NOW(),
                    fecha_fin = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                    tiempo_extension = ?,
                    precio_actual = NULL,
                    id_mejor_postor = NULL,
                    estado = 'programada'
                WHERE id_subasta = ?");
            $stmt->bind_param('iii', $duracionMin, $extensionSeg, $idSubasta);
            $stmt->execute();
        }
        $stmt->close();

        registrarAuditoria($db, $idUsuario, 'Creó subasta', $idSubasta);
        $db->commit();
        return [true, 'Subasta creada (programada). Ya se puede abrir.'];
    } catch (Throwable $e) {
        $db->rollback();
        return [false, 'No se pudo crear la subasta.'];
    }
}

/**
 * ABRIR subasta (RF06 - moderador y administrador): programada -> activa.
 * El cronómetro arranca ahora y dura lo que se definió al crearla.
 */
function abrirSubasta(mysqli $db, int $idSubasta, int $idUsuario): array {
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("SELECT id_obra, TIMESTAMPDIFF(SECOND, fecha_inicio, fecha_fin) AS duracion
                              FROM subastas
                              WHERE id_subasta = ? AND estado = 'programada' FOR UPDATE");
        $stmt->bind_param('i', $idSubasta);
        $stmt->execute();
        $sub = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sub) {
            $db->rollback();
            return [false, 'La subasta no existe o no está programada.'];
        }

        $duracion = max(60, (int)$sub['duracion']);
        $idObra   = (int)$sub['id_obra'];

        $stmt = $db->prepare("UPDATE subastas
                              SET fecha_inicio = NOW(),
                                  fecha_fin = DATE_ADD(NOW(), INTERVAL ? SECOND),
                                  estado = 'activa'
                              WHERE id_subasta = ?");
        $stmt->bind_param('ii', $duracion, $idSubasta);
        $stmt->execute();
        $stmt->close();

        // La vista del cliente exige obras.estado = 'activa'
        $stmt = $db->prepare("UPDATE obras SET estado = 'activa', fecha_actualizacion = NOW() WHERE id_obra = ?");
        $stmt->bind_param('i', $idObra);
        $stmt->execute();
        $stmt->close();

        registrarAuditoria($db, $idUsuario, 'Abrió subasta', $idSubasta);
        $db->commit();
        return [true, 'Subasta abierta. El cronómetro ya está corriendo.'];
    } catch (Throwable $e) {
        $db->rollback();
        return [false, 'No se pudo abrir la subasta.'];
    }
}

/**
 * Cierra manualmente una subasta activa (fecha_fin pasa a ser ahora).
 */
function cerrarSubasta(mysqli $db, int $idSubasta, int $idUsuario): array {
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("UPDATE subastas s
            INNER JOIN obras o ON o.id_obra = s.id_obra
            SET s.estado = 'cerrada',
                s.fecha_fin = LEAST(s.fecha_fin, NOW()),
                o.estado = IF(s.id_mejor_postor IS NULL, 'pendiente', 'cerrada')
            WHERE s.id_subasta = ? AND s.estado = 'activa'");
        $stmt->bind_param('i', $idSubasta);
        $stmt->execute();
        $filas = $stmt->affected_rows;
        $stmt->close();

        if ($filas <= 0) {
            $db->rollback();
            return [false, 'La subasta no existe o ya estaba cerrada.'];
        }

        registrarAuditoria($db, $idUsuario, 'Cerró subasta', $idSubasta);
        $db->commit();
        return [true, 'Subasta cerrada.'];
    } catch (Throwable $e) {
        $db->rollback();
        return [false, 'No se pudo cerrar la subasta.'];
    }
}

/**
 * EXTENSIÓN DEL CRONÓMETRO (anti-sniping).
 * Si entra una puja cuando faltan menos de tiempo_extension segundos (10 según la documentación), se suman
 * tiempo_extension segundos a fecha_fin. Usa la columna `tiempo_extension`
 * de cada subasta.
 *
 * Llamar desde el procesamiento de una puja, DENTRO de la misma transacción
 * donde se inserta la puja y se actualiza precio_actual / id_mejor_postor:
 *
 *   $db->begin_transaction();
 *   ... validar monto, INSERT en pujas, UPDATE subastas SET precio_actual, id_mejor_postor ...
 *   $extendida = extenderCronometroSiCorresponde($db, $idSubasta);
 *   $db->commit();
 *
 * Devuelve true si se extendió el tiempo.
 */
function extenderCronometroSiCorresponde(mysqli $db, int $idSubasta): bool {
    $stmt = $db->prepare("UPDATE subastas
        SET fecha_fin = DATE_ADD(fecha_fin, INTERVAL tiempo_extension SECOND)
        WHERE id_subasta = ?
          AND estado = 'activa'
          AND tiempo_extension > 0
          AND fecha_fin > NOW()
          AND TIMESTAMPDIFF(SECOND, NOW(), fecha_fin) < tiempo_extension");
    $stmt->bind_param('i', $idSubasta);
    $stmt->execute();
    $extendida = $stmt->affected_rows > 0;
    $stmt->close();

    return $extendida;
}

/**
 * Estado actual del cronómetro de una subasta (lo usa la API para sincronizar).
 */
function obtenerTiempoSubasta(mysqli $db, int $idSubasta): ?array {
    $stmt = $db->prepare("SELECT id_subasta, estado, fecha_fin, precio_actual,
                                 GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), fecha_fin)) AS segundos_restantes
                          FROM subastas WHERE id_subasta = ?");
    $stmt->bind_param('i', $idSubasta);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fila ?: null;
}