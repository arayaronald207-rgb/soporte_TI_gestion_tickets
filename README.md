# Soporte TI — Sistema de Gestión de Tickets de Soporte Técnico

Aplicación web tipo *Help Desk* para registrar, asignar, atender y dar seguimiento a incidencias tecnológicas de laboratorios, oficinas y equipos de cómputo. Proyecto final del curso **Administración y Control de Centros de Informática** (UISIL).

## Funcionalidades

**Módulo de tickets**

- Creación de tickets con título, descripción, categoría (Hardware, Software, Red) y prioridad (Baja, Media, Alta).
- Asignación y reasignación de tickets a técnicos (administrador).
- Ciclo de vida: Pendiente → En proceso → Resuelta, con reapertura.
- Historial auditable y comentarios de seguimiento por ticket.
- Archivado y restauración de tickets (no se borran).
- Listado con filtros por estado, prioridad, categoría, técnico, SLA y búsqueda de texto.
- Gestión de usuarios (administrador).

**Funciones adicionales**

- **Dashboard de métricas:** tickets activos, sin asignar, por estado, prioridad, categoría y técnico, y tiempo promedio de resolución.
- **Acuerdos de nivel de servicio (SLA):** plazos por prioridad (Alta 4 h, Media 24 h, Baja 72 h) con alerta visual *A tiempo / Por vencer / Vencido*.
- **Encuestas de satisfacción:** el solicitante consulta su ticket con un código de seguimiento y califica la atención (1 a 5 estrellas) cuando está resuelto.

## Roles

| Rol | Acceso |
|---|---|
| Visitante / Solicitante | Sin cuenta: registra tickets, consulta su ticket con el código de seguimiento y responde la encuesta. |
| Técnico | Atiende solo sus tickets asignados: comentarios, edición, cambio de estado, SLA y métricas propias. |
| Administrador | Todo lo anterior sobre cualquier ticket, más asignación, archivado y gestión de usuarios. |

## Tecnologías

PHP 8.2 (mysqli), MariaDB 10.4 / MySQL, Apache 2.4 (XAMPP), HTML5, CSS3, JavaScript, Chart.js y Font Awesome (CDN).

## Instalación rápida (Windows + XAMPP)

1. Copiar el proyecto en `C:\xampp\htdocs\gestion_incidencias` e iniciar **Apache** y **MySQL** en XAMPP.
2. Crear la base de datos e importar los scripts **en este orden** (desde `C:\xampp\mysql\bin`, en cmd):

   ```bat
   mysql -u root -e "CREATE DATABASE gestion_incidencias CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
   mysql -u root gestion_incidencias < C:\xampp\htdocs\gestion_incidencias\gestion_incidencias.sql
   mysql -u root gestion_incidencias < C:\xampp\htdocs\gestion_incidencias\migracion_gestion_tickets.sql
   mysql -u root gestion_incidencias < C:\xampp\htdocs\gestion_incidencias\migracion_sla_encuestas.sql
   ```

3. Crear una cuenta de base de datos con privilegios mínimos para la aplicación (comandos completos en el Manual Técnico, paso 5).
4. Configurar la conexión: copiar `config/config.example.php` como `config/config.local.php` y escribir el usuario y la contraseña de esa cuenta. **`config.local.php` no se sube al repositorio.**
5. Abrir `http://localhost/gestion_incidencias/`.

Las instrucciones detalladas están en [docs/Manual_Tecnico.md](docs/Manual_Tecnico.md).

## Cuentas de demostración

| Usuario | Rol | Contraseña |
|---|---|---|
| `admin` | Administrador | `Demo.Admin2026` |
| `tecnico1` | Técnico | `Demo.Tecnico2026` |

Son contraseñas públicas solo para evaluación y demostración. Si el sistema se usa con datos reales, cámbielas desde **Gestión de usuarios**.

## Documentación

- [Manual Técnico](docs/Manual_Tecnico.md): arquitectura, base de datos, módulos, instalación y seguridad.
- [Manual de Usuario](docs/Manual_Usuario.md): uso del sistema por rol y funciones adicionales.

## Estructura

| Ruta | Contenido |
|---|---|
| `index.php`, `registrar.php`, `seguimiento.php`, `login.php`, `incidencias.php` | Páginas públicas y consulta |
| `admin/` | Panel, detalle, asignación, estados, edición, archivado y usuarios |
| `config/` | Conexión, sesión, autorización, historial, métricas, SLA y encuestas |
| `includes/`, `css/`, `js/`, `fonts/`, `imagenes/` | Interfaz |
| `*.sql` | Esquema base con datos de demostración y migraciones |
| `tests/` | Pruebas funcionales en Python (base de pruebas aislada) |
| `docs/` | Manuales técnico y de usuario |

## Autor

Ronald Araya — Ingeniería en Sistemas, UISIL, 2026.
