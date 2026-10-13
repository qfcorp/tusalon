# TuSalón

Software para peluquerías y barberías de Ecuador, de la marca Tukán Software.
Dirección: **tusalon.tukanec.com** (servidor qfcorp). Web de la marca: tukansoftware.com.

Un mismo local puede tener 4 tipos de peluquero a la vez: dueño que atiende, empleado (sueldo y/o comisión), porcentaje (50/50, 60/40) y alquiler de puesto.

- Plan y avance del proyecto: [BLOC_DE_NOTAS.md](BLOC_DE_NOTAS.md)
- Cómo se reparte el dinero: [docs/COMO_FUNCIONA_EL_DINERO.md](docs/COMO_FUNCIONA_EL_DINERO.md)

## Direcciones
| Quién | Dirección |
|---|---|
| Dueño y recepción | `/` (entrar con correo y contraseña) |
| Peluquero | `/` con el acceso que le crea el dueño → su portal |
| Clientes (reservas) | `/?r=reservar&s=<nombre-del-salón>` |
| Tukán (super administrador) | `/?r=admin_entrar` · cuenta creada con `php bin/crear_admin.php` en el servidor · o con un clic desde el Panel Tukán Central |
| Panel Tukán Central (conector) | `/tukan_central.php?accion=sso&t=…` (entrar) · `/tukan_central.php?accion=estado&t=…` (cifras en JSON) |
| Cliente (su cita: confirmar, cambiar, cancelar, calificar) | `/?r=confirmar&t=<enlace secreto>` (le llega por WhatsApp o Telegram) |

## Tecnología
PHP 8.3+ y PostgreSQL 16.

## Estructura
| Carpeta | Qué tiene |
|---|---|
| `db/schema.sql` | Todas las tablas |
| `src/` | La lógica: planes, profesionales, liquidación, rol de pagos, facturas recibidas, agenda, avisos, Telegram, tareas automáticas y calificaciones |
| `bin/tareas.php` | Tareas automáticas (correr cada hora con cron) |
| `deploy/respaldo.sh` | Respaldo diario de la base y las fotos (guarda 14 días) |
| `tests/` | Pruebas de lógica y de pantallas |

## Instalar en un servidor (Ubuntu + PHP 8.5 + PostgreSQL + nginx)
Una sola línea, como el usuario normal (no root). Se puede repetir para actualizar: si ya hay salones, no borra la base.

```bash
curl -fsSL https://raw.githubusercontent.com/qfcorp/tusalon/main/deploy/instalar.sh -o ~/instalar_tusalon.sh && bash ~/instalar_tusalon.sh
```

Después, en Cloudflare (Zero Trust → Networks → Tunnels → tu túnel → Public Hostname) agregar `tusalon.tukanec.com` → `HTTP` → `localhost:80`.

## Conectar con el Panel Tukán Central (panel.tukanec.com)
El panel central muestra las cifras de todos los programas Tukán y deja entrar a cada panel con un clic.
1. En el panel central, agrega la instalación de TuSalón: te da un **ID** (ej. `tusalon`) y un **SECRETO** (64 letras y números).
2. En el servidor, una sola línea:
   ```bash
   sudo bash /var/www/tusalon/deploy/conectar_central.sh {ID} {SECRETO} {URL}
   ```
   Ejemplo: `sudo bash /var/www/tusalon/deploy/conectar_central.sh tusalon 3f9a…c1 https://panel.tukanec.com`.
   Guarda `TUKAN_CENTRAL_ID`, `TUKAN_CENTRAL_SECRET` y `TUKAN_CENTRAL_URL` en `config/.env` (lo demás no se toca), prepara las tablas y hace una prueba: debe terminar en `OK`.
3. Listo: al entrar desde el panel central, el panel de TuSalón muestra el botón **← Panel Tukán** para volver.

- Sin `TUKAN_CENTRAL_ID` o `TUKAN_CENTRAL_SECRET`, `/tukan_central.php` responde 404 y todo sigue como antes. El instalador conserva estas líneas al actualizar.
- **Entrar solo desde el panel central:** agrega `TUKAN_SOLO_CENTRAL=1` en `config/.env`. La página `/?r=admin_entrar` dice "El ingreso del super usuario es solo desde el Panel Tukán" y no acepta contraseñas. El ingreso de los salones y de los clientes no cambia.
- **Emergencia** (el panel central no responde): pon `TUKAN_SOLO_CENTRAL=0` en `config/.env` y entra con tu clave; si no la recuerdas, `php /var/www/tusalon/bin/crear_admin.php` la cambia.
- Cada entrada desde el panel central queda en "Últimos movimientos del panel" ("Entró desde el Panel Tukán Central"). La primera vez se crea el super usuario con el correo del panel central (con una contraseña al azar que nadie conoce).
- Cifras que ve el panel central: salones por estado (las mismas del panel de TuSalón), nuevos en 30 días, cobrado del mes y del mes anterior, pruebas que vencen en 7 días, pagos vencidos, citas del mes, clientes, peluqueros y usuarios. Sin datos personales (solo nombres de salones).
- Seguridad: cada enlace dura 60 segundos y sirve una sola vez; máximo 30 pedidos por minuto por IP. Protocolo: Tukán Central v1 (igual en todos los programas Tukán).

## Tareas automáticas y respaldo (en el servidor)
El instalador ya las deja programadas. Son estas dos líneas de cron:
```
5 * * * * php /var/www/tusalon/bin/tareas.php >> /var/log/tusalon-tareas.log 2>&1
15 3 * * * /var/www/tusalon/deploy/respaldo.sh >> /var/log/tusalon-respaldo.log 2>&1
```

- Cada hora: recordatorios de mañana (desde las 9:00), cumpleaños (desde las 8:00), pedir calificación, reporte del mes (día 1 desde las 7:00). Cada cosa se envía una sola vez.
- Cada día a las 3:15: copia de la base y de las fotos en `/var/backups/tusalon` (solo legible por el servidor). Para restaurar: `pg_restore -d tusalon --clean archivo.dump`.

## Correr las pruebas
Solo en una base de prueba (el script la borra y la vuelve a crear):

```bash
# Lógica (424 pruebas: run_tests.php + prueba_central.php, con Telegram simulado en el puerto 8098)
bash tests/run_logica.sh
# Pantallas de punta a punta (391 pruebas: prueba_web.sh + prueba_web_central.sh; levanta solo los sitios de prueba,
# Payphone y Telegram simulados). Opcional: DB=<base> PUERTO=<puerto>
PGPASSWORD=prueba bash tests/run_web.sh
# Solo el conector con el Panel Tukán Central
TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_central.php
PGPASSWORD=prueba bash tests/run_web.sh central
```
Las pruebas de siempre se pueden seguir corriendo a mano como antes (`php tests/run_tests.php` y `bash tests/prueba_web.sh` con el sitio corriendo).
