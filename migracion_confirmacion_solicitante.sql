-- Migracion 4: confirmacion del solicitante ("¿ya funciona?").
-- Destino: MariaDB 10.4+ / InnoDB / utf8mb4_general_ci.
-- Ejecutar UNA sola vez, DESPUES de gestion_incidencias.sql,
-- migracion_gestion_tickets.sql, migracion_sla_encuestas.sql y
-- migracion_estado_cerrado.sql, sobre la base seleccionada en el cliente
-- (desde C:\xampp\mysql\bin, en cmd):
--   mysql -u root gestion_incidencias < C:\xampp\htdocs\gestion_incidencias\migracion_confirmacion_solicitante.sql
--
-- ANTES DE EJECUTAR: respaldar la base activa, por ejemplo:
--   mysqldump -u root --single-transaction --routines --triggers gestion_incidencias > respaldo_antes_confirmacion.sql
-- Si se usan las pruebas automatizadas, aplicar tambien este script a la base
-- gestion_incidencias_prueba (las pruebas comparan su esquema antes y despues).
--
-- FLUJO: el tecnico asignado (o el administrador) pregunta al solicitante si
-- el problema quedo resuelto, con el ticket En proceso o Resuelta. El
-- solicitante responde desde "Consultar mi ticket" con su codigo de seguimiento.
--   * La respuesta solo informa: no cambia el estado del ticket.
--   * Una sola pregunta pendiente por ticket y una respuesta por pregunta
--     (validado en PHP bajo bloqueo del ticket).
--   * "sigue_fallando" exige comentario; con "funciona" es opcional.
--   * La pregunta queda en incidencia_historial (tipo confirmacion_solicitada,
--     autor = usuario del sistema). La respuesta NO va al historial, porque el
--     solicitante no tiene usuario: se lee de confirmaciones_solicitante.
--
-- CONSERVACION: se crea una tabla nueva vacia y se agrega un valor al final del
-- ENUM tipo del historial. No cambian datos, IDs ni AUTO_INCREMENT.
-- ALTER/CREATE TABLE producen commit implicito: ante un error, revisar y
-- recuperar desde el respaldo; no reimportar el archivo a ciegas (no es idempotente).
--
-- Despues de ejecutar este script, otorgar a la cuenta de la aplicacion:
--   GRANT SELECT, INSERT, UPDATE ON gestion_incidencias.confirmaciones_solicitante
--         TO 'soporte_ti_app'@'localhost';
--   FLUSH PRIVILEGES;

-- 1. Preguntas al solicitante y sus respuestas.
CREATE TABLE `confirmaciones_solicitante` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `incidencia_id` INT(11) NOT NULL,
    `pregunta` TEXT NOT NULL COMMENT 'Visible para el solicitante; maximo 500 caracteres (validado en PHP)',
    `preguntado_por` INT(11) NOT NULL COMMENT 'Usuario del sistema que hizo la pregunta',
    `fecha_pregunta` DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
    `respuesta` ENUM('funciona', 'sigue_fallando') NULL DEFAULT NULL COMMENT 'NULL mientras esta pendiente',
    `comentario` TEXT NULL DEFAULT NULL COMMENT 'Del solicitante; maximo 1000 caracteres (validado en PHP)',
    `fecha_respuesta` DATETIME NULL DEFAULT NULL COMMENT 'UTC',

    PRIMARY KEY (`id`),
    KEY `idx_conf_incidencia_fecha` (`incidencia_id`, `fecha_pregunta`, `id`),
    KEY `idx_conf_preguntado_por` (`preguntado_por`),

    CONSTRAINT `chk_conf_pregunta`
        CHECK (CHAR_LENGTH(TRIM(`pregunta`)) > 0),
    CONSTRAINT `chk_conf_respuesta_fecha`
        CHECK ((`respuesta` IS NULL) = (`fecha_respuesta` IS NULL)),
    CONSTRAINT `chk_conf_comentario`
        CHECK (`comentario` IS NULL OR (`respuesta` IS NOT NULL AND CHAR_LENGTH(TRIM(`comentario`)) > 0)),
    CONSTRAINT `chk_conf_sigue_fallando`
        CHECK (`respuesta` IS NULL OR `respuesta` <> 'sigue_fallando' OR `comentario` IS NOT NULL),

    CONSTRAINT `fk_conf_incidencia`
        FOREIGN KEY (`incidencia_id`) REFERENCES `incidencias` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_conf_preguntado_por`
        FOREIGN KEY (`preguntado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Historial: nuevo tipo de evento para la pregunta.
ALTER TABLE `incidencia_historial`
    DROP CONSTRAINT `chk_gt_hist_tipo`;

ALTER TABLE `incidencia_historial`
    MODIFY COLUMN `tipo` ENUM(
        'creacion', 'comentario', 'edicion', 'asignacion',
        'cambio_estado', 'archivado', 'desarchivado', 'confirmacion_solicitada'
    ) NOT NULL,
    ADD CONSTRAINT `chk_gt_hist_tipo`
        CHECK (`tipo` IN (
            'creacion', 'comentario', 'edicion', 'asignacion',
            'cambio_estado', 'archivado', 'desarchivado', 'confirmacion_solicitada'
        ));

-- VERIFICACION POSTERIOR:
--   SHOW CREATE TABLE confirmaciones_solicitante;  -- tabla vacia con sus CHECK y FK
--   SHOW CREATE TABLE incidencia_historial;        -- tipo y chk_gt_hist_tipo incluyen confirmacion_solicitada
--   SELECT tipo, COUNT(*) FROM incidencia_historial GROUP BY tipo;  -- mismas cantidades que antes
