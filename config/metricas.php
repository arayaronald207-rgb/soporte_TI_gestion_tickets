<?php

// Solo recibe la cuenta validada en servidor. Los filtros GET no cambian el alcance.
function metricasIncidencias(mysqli $conn, array $usuario): array
{
    $admin = (int) ($usuario['rol'] === 'admin');
    $id = (int) $usuario['id'];
    // fecha_registro es TIMESTAMP; fecha_resolucion es DATETIME almacenado en UTC.
    $conn->query("SET SESSION time_zone = '+00:00'");
    $datos = ['activos' => 0, 'archivados' => 0, 'sin_asignar' => 0,
        'estados' => ['Pendiente' => 0, 'En proceso' => 0, 'Resuelta' => 0],
        'prioridades' => ['Baja' => 0, 'Media' => 0, 'Alta' => 0],
        'categorias' => ['hardware' => 0, 'software' => 0, 'red' => 0, 'sin_categoria' => 0],
        'muestras_resolucion' => 0, 'promedio_segundos' => null,
        'asignados_por_tecnico' => [], 'resueltos_por_tecnico' => []];
    $stmt = $conn->prepare("SELECT archivado, estado, prioridad, categoria, COUNT(*) AS cantidad,
        SUM(tecnico_id IS NULL) AS sin_asignar,
        COUNT(CASE WHEN estado = 'Resuelta' AND fecha_registro >= '1970-01-01 00:00:01'
            AND fecha_resolucion >= fecha_registro AND fecha_resolucion <= UTC_TIMESTAMP()
            AND DAY(fecha_resolucion) BETWEEN 1 AND DAY(LAST_DAY(fecha_resolucion))
            AND TIMESTAMPDIFF(SECOND, fecha_registro, fecha_resolucion) IS NOT NULL
            THEN 1 END) AS muestras,
        SUM(CASE WHEN estado = 'Resuelta' AND fecha_registro >= '1970-01-01 00:00:01'
            AND fecha_resolucion >= fecha_registro AND fecha_resolucion <= UTC_TIMESTAMP()
            AND DAY(fecha_resolucion) BETWEEN 1 AND DAY(LAST_DAY(fecha_resolucion))
            AND TIMESTAMPDIFF(SECOND, fecha_registro, fecha_resolucion) IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, fecha_registro, fecha_resolucion) END) AS segundos
        FROM incidencias WHERE (? = 1 OR tecnico_id = ?)
        GROUP BY archivado, estado, prioridad, categoria");
    $stmt->bind_param('ii', $admin, $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $segundos = 0;
    while ($fila = $resultado->fetch_assoc()) {
        $cantidad = (int) $fila['cantidad'];
        if ((int) $fila['archivado'] === 1) {
            $datos['archivados'] += $cantidad;
            continue;
        }
        $datos['activos'] += $cantidad;
        $datos['sin_asignar'] += (int) $fila['sin_asignar'];
        $datos['estados'][$fila['estado']] += $cantidad;
        $datos['prioridades'][$fila['prioridad']] += $cantidad;
        $datos['categorias'][$fila['categoria'] ?? 'sin_categoria'] += $cantidad;
        $datos['muestras_resolucion'] += (int) $fila['muestras'];
        $segundos += (float) ($fila['segundos'] ?? 0);
    }
    $stmt->close();
    if ($datos['muestras_resolucion'] > 0) {
        $datos['promedio_segundos'] = $segundos / $datos['muestras_resolucion'];
    }
    // Dos agrupaciones distintas: responsable actual y autor real de resolucion.
    // No filtrar por rol actual: una cuenta puede haber cambiado de rol o actividad.
    foreach (['asignados_por_tecnico' => 'tecnico_id', 'resueltos_por_tecnico' => 'tecnico_resolucion_id'] as $clave => $columna) {
        $condicion = $clave === 'resueltos_por_tecnico' ? " AND i.estado = 'Resuelta'" : '';
        // Los identificadores SQL proceden exclusivamente de la lista fija anterior.
        $stmt = $conn->prepare("SELECT u.id, u.usuario, COUNT(*) AS cantidad
            FROM incidencias i LEFT JOIN usuarios u ON u.id = i.$columna
            WHERE i.archivado = 0 AND (? = 1 OR i.tecnico_id = ?) $condicion
            GROUP BY u.id, u.usuario ORDER BY cantidad DESC, u.id ASC");
        $stmt->bind_param('ii', $admin, $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($fila = $resultado->fetch_assoc()) {
            $datos[$clave][] = ['id' => $fila['id'] === null ? null : (int) $fila['id'],
                'nombre' => $fila['usuario'] ?? ($clave === 'asignados_por_tecnico' ? 'Sin asignar' : 'Sin técnico de resolución registrado'),
                'cantidad' => (int) $fila['cantidad']];
        }
        $stmt->close();
    }
    return $datos;
}
