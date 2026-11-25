-- Migracion 3: estado "Cerrado" en el ciclo de vida del ticket.
-- Destino: MariaDB 10.4+ / InnoDB / utf8mb4_general_ci.
-- Ejecutar UNA sola vez, DESPUES de gestion_incidencias.sql,
-- migracion_gestion_tickets.sql y migracion_sla_encuestas.sql, sobre la base
-- seleccionada en el cliente (desde C:\xampp\mysql\bin, en cmd):
--   mysql -u root gestion_incidencias < C:\xampp\htdocs\gestion_incidencias\migracion_estado_cerrado.sql
--
-- ANTES DE EJECUTAR: respaldar la base activa, por ejemplo:
--   mysqldump -u root --single-transaction --routines --triggers gestion_incidencias > respaldo_antes_cerrado.sql
-- Si se usan las pruebas automatizadas, aplicar tambien este script a la base
-- gestion_incidencias_prueba (las pruebas comparan su esquema antes y despues).
--
-- FLUJO: Pendiente -> En proceso -> Resuelta -> Cerrado.
--   * Solo se cierra un ticket en Resuelta (lo validan PHP y esta restriccion).
--   * Al cerrar se guarda fecha_cierre (UTC) y se CONSERVAN fecha_resolucion y
--     tecnico_resolucion_id: el SLA y el promedio cuentan Resuelta y Cerrado.
--   * Quien cierra queda en incidencia_historial (usuario_id del evento).
--   * Cerrado es final: solo el administrador lo reabre (validado en PHP).
--
-- CONSERVACION: solo se agregan valores al final de los ENUM y una columna
-- nueva que admite NULL. No cambian datos, IDs ni AUTO_INCREMENT. Ningun ticket
-- existente queda Cerrado, de modo que la nueva restriccion acepta todas las filas.
-- La cuenta de la aplicacion tiene permisos por tabla (SELECT/INSERT/UPDATE en
-- incidencias): la columna nueva no requiere GRANT adicional.
-- ALTER TABLE produce commit implicito: ante un error, revisar y recuperar desde
-- el respaldo; no reimportar el archivo a ciegas (no es idempotente).

-- 1. Incidencias: nuevo estado final y fecha de cierre vigente.
ALTER TABLE `incidencias`
    MODIFY COLUMN `estado` ENUM('Pendiente', 'En proceso', 'Resuelta', 'Cerrado')
        NOT NULL DEFAULT 'Pendiente',
    ADD COLUMN `fecha_cierre` DATETIME NULL DEFAULT NULL
        COMMENT 'UTC: cierre vigente; NULL si el ticket no esta Cerrado'
        AFTER `tecnico_resolucion_id`,
    ADD CONSTRAINT `chk_inc_cierre`
        CHECK ((`estado` = 'Cerrado') = (`fecha_cierre` IS NOT NULL));

-- 2. Historial: los cambios de estado pueden registrar Cerrado.
ALTER TABLE `incidencia_historial`
    MODIFY COLUMN `estado_anterior` ENUM('Pendiente', 'En proceso', 'Resuelta', 'Cerrado') NULL DEFAULT NULL,
    MODIFY COLUMN `estado_nuevo` ENUM('Pendiente', 'En proceso', 'Resuelta', 'Cerrado') NULL DEFAULT NULL;

-- VERIFICACION POSTERIOR:
--   SHOW CREATE TABLE incidencias;            -- estado incluye 'Cerrado', existe fecha_cierre y chk_inc_cierre
--   SHOW CREATE TABLE incidencia_historial;   -- estado_anterior/estado_nuevo incluyen 'Cerrado'
--   SELECT estado, COUNT(*) FROM incidencias GROUP BY estado;  -- mismas cantidades que antes
