-- Migracion 2: Acuerdos de nivel de servicio (SLA) y encuestas de satisfaccion.
-- Destino: MariaDB 10.4+ / InnoDB / utf8mb4_general_ci.
-- Ejecutar UNA sola vez, DESPUES de gestion_incidencias.sql y
-- migracion_gestion_tickets.sql, sobre la base seleccionada en el cliente:
--   mysql -u root gestion_incidencias < migracion_sla_encuestas.sql
--
-- SLA: no requiere columnas nuevas. Los plazos por prioridad se definen en
-- config/sla.php y se calculan a partir de fecha_registro y fecha_resolucion.
--
-- ENCUESTAS: el solicitante no tiene cuenta. Al registrar un ticket recibe un
-- codigo de seguimiento; solo se guarda su hash SHA-256 (nunca el codigo).
-- Con el numero de ticket y el codigo puede consultar el estado y, cuando el
-- ticket esta Resuelta, responder una unica encuesta de satisfaccion.
--
-- Despues de ejecutar este script, otorgar a la cuenta de la aplicacion:
--   GRANT SELECT, INSERT ON gestion_incidencias.encuestas_satisfaccion
--         TO 'soporte_ti_app'@'localhost';

ALTER TABLE `incidencias`
    ADD COLUMN `codigo_seguimiento_hash` CHAR(64) NULL DEFAULT NULL
        COMMENT 'SHA-256 del codigo de seguimiento entregado al solicitante; NULL si no tiene';

CREATE TABLE `encuestas_satisfaccion` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `incidencia_id` INT(11) NOT NULL,
    `calificacion` TINYINT(1) NOT NULL COMMENT '1 (muy insatisfecho) a 5 (muy satisfecho)',
    `comentario` TEXT NULL DEFAULT NULL COMMENT 'Opcional, maximo 1000 caracteres (validado en PHP)',
    `fecha` DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC: momento de la respuesta',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_encuesta_incidencia` (`incidencia_id`),

    CONSTRAINT `chk_encuesta_calificacion`
        CHECK (`calificacion` BETWEEN 1 AND 5),
    CONSTRAINT `chk_encuesta_comentario`
        CHECK (`comentario` IS NULL OR CHAR_LENGTH(TRIM(`comentario`)) > 0),
    CONSTRAINT `fk_encuesta_incidencia`
        FOREIGN KEY (`incidencia_id`) REFERENCES `incidencias` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
