<?php

// El rol y la actividad se consultan en la base, nunca se confian al formulario.
function usuarioAutenticado(mysqli $conn, string $login, bool $obligatorio = true, bool $bloquear = false): ?array
{
    $id = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) {
        if ($obligatorio) {
            if ($bloquear) {
                $conn->rollback();
            }
            header('Location: ' . $login);
            exit;
        }
        return null;
    }

    $sql = 'SELECT id, usuario, rol, activo FROM usuarios WHERE id = ?';
    if ($bloquear) {
        $sql .= ' LOCK IN SHARE MODE';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$usuario || (int) $usuario['activo'] !== 1 ||
        !in_array($usuario['rol'], ['admin', 'tecnico'], true)) {
        if ($bloquear) {
            $conn->rollback();
        }
        $_SESSION = [];
        session_destroy();
        header('Location: ' . $login);
        exit;
    }

    $_SESSION['admin_usuario'] = $usuario['usuario'];
    $_SESSION['admin_rol'] = $usuario['rol'];
    return $usuario;
}

function exigirAdministrador(array $usuario): void
{
    if ($usuario['rol'] !== 'admin') {
        http_response_code(403);
        exit('Acceso denegado. Esta operacion requiere un administrador.');
    }
}

function incidenciaPermitida(mysqli $conn, int $id, array $usuario, bool $bloquear = false): array
{
    $es_admin = (int) ($usuario['rol'] === 'admin');
    $usuario_id = (int) $usuario['id'];
    $sql = 'SELECT * FROM incidencias WHERE id = ? AND (? = 1 OR (tecnico_id = ? AND archivado = 0))';
    if ($bloquear) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $id, $es_admin, $usuario_id);
    $stmt->execute();
    $incidencia = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$incidencia) {
        if ($bloquear) {
            $conn->rollback();
        }
        // Igual respuesta para IDs inexistentes y tickets de otras cuentas.
        http_response_code(404);
        exit('No se encontro la incidencia o no tienes acceso a ella.');
    }
    return $incidencia;
}

// Antes de escribir, vuelve a comprobar cuenta y asignacion bajo bloqueo.
// El llamador debe confirmar o revertir la transaccion.
function bloquearIncidenciaParaCambio(mysqli $conn, int $id, bool $solo_admin = false): array
{
    $conn->begin_transaction();
    try {
        $usuario = usuarioAutenticado($conn, '../login.php', true, true);
        if ($solo_admin && $usuario['rol'] !== 'admin') {
            $conn->rollback();
            exigirAdministrador($usuario);
        }
        return incidenciaPermitida($conn, $id, $usuario, true);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
