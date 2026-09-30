# Plataforma clínica: accesos, afiliación de pacientes y ventas

Examen técnico Nº001-2026-AJMZ-CPS · Postulante: Johan Ayme Nina · Datos 100 % ficticios.

## 1. Tecnología y versiones
- PHP **[completar con `php -v`, ej. 8.2.x]** (puro, sin framework) con PDO
- MySQL/MariaDB **[completar, ej. MariaDB 10.4 de XAMPP]**
- XAMPP + Sublime Text. Frontend: HTML + CSS + 3 líneas de JavaScript (sin librerías).

Se eligió PHP puro (sin Laravel) por el plazo de 5 días y por dominio de la herramienta. Los controles que Laravel trae listos
(hash, CSRF, consultas parametrizadas, escape de salida, transacciones) están implementados a mano en `lib.php`.

## 2. Instalación desde cero (Windows + XAMPP)
1. Copiar la carpeta `clinica_cps` a `C:\xampp\htdocs\`.
2. Abrir el panel de XAMPP e iniciar **Apache** y **MySQL**.
3. (Opcional) copiar `.env.example` a `.env` si tu MySQL tiene otra clave. Por defecto: usuario `root`, sin clave.
4. Abrir en el navegador `http://localhost/clinica_cps/install.php`. Crea la BD `clinica_cps`, las tablas y los datos de prueba.
5. Entrar a `http://localhost/clinica_cps/login.php`.

(Alternativa por consola: `C:\xampp\php\php.exe install.php`.) `install.php` borra y recrea las tablas: solo para pruebas.

## 3. Credenciales de prueba (solo entorno de prueba)
| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | admin@clinica.test | Admin123* |
| Admisión | admision@clinica.test | Admision123* |
| Vendedor 1 | vendedor1@clinica.test | Vendedor123* |
| Vendedor 2 | vendedor2@clinica.test | Vendedor123* |

Datos iniciales: 5 pacientes (1 inactivo: Rosa Flores), 5 productos (P004 sin stock, P005 inactivo).

## 4. Pruebas automatizadas
```
C:\xampp\php\php.exe tests\run.php
```
Usa una BD aparte (`clinica_test`) que recrea sola. Cubre: permiso denegado (caso 03), stock insuficiente con líneas repetidas (06),
venta correcta S/ 57.50 con stock 7/4 (05), reversión por fallo intermedio (10) y precio histórico (08).

## 5. Mapa de archivos
| Archivo | Qué hace |
|---|---|
| `lib.php` | Núcleo: conexión PDO, sesión, CSRF, permisos (`exigir`, `puede`), plantilla, **lógica de ventas** (`registrar_venta`) |
| `schema.sql` / `instalar.php` / `install.php` | Tablas y datos iniciales (contraseñas con `password_hash`) |
| `login.php`, `logout.php`, `index.php` | Acceso y cierre de sesión |
| `usuarios.php`, `roles.php` | Cuentas internas; roles y asignación de permisos |
| `pacientes.php`, `productos.php` | Afiliación y catálogo (alta, edición, búsqueda, filtro por estado) |
| `ventas.php` | Nueva venta (seleccionar → revisar total → confirmar), listado con filtros y detalle |
| `tests/run.php` | Pruebas automatizadas |

## 6. Modelo de datos
```
roles 1───* usuarios 1───* ventas *───1 pacientes
  │                          │
  └─* rol_permiso *─1 permisos   ventas 1───* venta_detalle *───1 productos
```
- `usuarios.rol_id → roles.id`; `rol_permiso (rol_id, permiso_id)` PK compuesta; `permisos.codigo` único (ej. `ventas.crear`).
- `pacientes`: `UNIQUE (tipo_doc, num_doc)` y `codigo` único (AF-000001).
- `productos`: `codigo` único, `CHECK precio > 0`, `CHECK stock >= 0`, precio DECIMAL(10,2).
- `ventas`: código único (V-000001), `usuario_id` = vendedor de la sesión, total DECIMAL.
- `venta_detalle`: guarda `precio_unitario` aplicado (el histórico no cambia si cambia el catálogo).

## 7. Decisiones de seguridad y lógica
- **Contraseñas**: `password_hash` / `password_verify`. `session_regenerate_id(true)` al ingresar; `logout` destruye la sesión.
- **Usuario inactivo**: en cada petición se relee el usuario de la BD; si está inactivo, se cierra su sesión.
- **Permisos en el servidor**: cada página llama `exigir('modulo.accion')`, que consulta la BD en cada petición. Ocultar un botón no es el control.
- **Ventas propias**: la consulta del vendedor lleva `usuario_id = <sesión>`. Si pide la venta de otro, recibe "no encontrada".
- **Venta**: el servidor ignora precios/totales/vendedor del navegador; suma cantidades repetidas; valida paciente y productos activos y stock;
  todo dentro de una transacción con `SELECT ... FOR UPDATE` (protege también ante ventas simultáneas) y `UPDATE ... WHERE stock >= cantidad`.
- **Otros**: consultas parametrizadas (PDO), `htmlspecialchars` en toda salida, token CSRF en todos los POST, botón de confirmar se deshabilita tras el clic (doble envío).

## 8. Lista de comprobación de los 10 casos (marcar y adjuntar captura)
| # | Pasos | Resultado esperado | OK |
|---|---|---|---|
| 01 | Login con clave incorrecta; luego ingresar bien y pulsar **Salir**; intentar abrir `ventas.php` | Error de login; tras salir redirige a login | [ ] |
| 02 | Vendedor abre `usuarios.php` o `usuarios.php?a=editar&id=3` | Error 403 | [ ] |
| 03 | Admin: Roles → Vendedor → quitar `ventas.crear`. Vendedor recarga `ventas.php?a=nueva` | Error 403 (también con la URL directa) | [ ] |
| 04 | Admisión: afiliar DNI 70000001 | "Ya existe un paciente con ese tipo y número de documento" | [ ] |
| 05 | Vendedor: paciente Ana Quispe, 3 Botellas + 1 Kit → Revisar → Confirmar | Total S/ 57.50; stock 7 y 4 | [ ] |
| 06 | Vender 11 botellas (10 en stock), o 6 + 5 en dos líneas | Rechazo total, stock intacto | [ ] |
| 07 | Con F12 (Herramientas) editar el `value` de un campo o agregar `precio=0.01` | El total sigue calculado con precios de la BD | [ ] |
| 08 | Admin cambia el precio de la Botella; abrir la venta anterior | Detalle mantiene S/ 12.50 | [ ] |
| 09 | Vendedor 2 abre `ventas.php?a=ver&id=<id de venta del vendedor 1>` | "Venta no encontrada" | [ ] |
| 10 | Ejecutar `tests\run.php` (prueba "Caso 10") | OK: se revierte cabecera y stock | [ ] |

## 9. Pendientes / fuera de alcance
- Sin recuperación de contraseña, anulación de ventas ni auditoría (extras opcionales, no incluidos).
- Sin HTTPS/hosting: uso local. El administrador no puede desactivarse ni cambiarse su propio rol.

## 10. Horas dedicadas y herramientas
- Horas dedicadas: **[completar]**
- Herramientas de IA: se utilizó **Claude (Anthropic)** como asistente para generar el código base. Revisé, probé y puedo explicar y modificar cada archivo.
- Librerías: ninguna externa (PHP estándar + PDO).
