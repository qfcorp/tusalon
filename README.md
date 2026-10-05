# TuSalón

Software para peluquerías y barberías de Ecuador, de la marca Tukán Software.
Dirección provisional: **tusalon.qfradioec.com** (servidor qfcorp).

Un mismo local puede tener 4 tipos de peluquero a la vez: dueño que atiende, empleado (sueldo y/o comisión), porcentaje (50/50, 60/40) y alquiler de puesto.

- Plan y avance del proyecto: [BLOC_DE_NOTAS.md](BLOC_DE_NOTAS.md)
- Cómo se reparte el dinero: [docs/COMO_FUNCIONA_EL_DINERO.md](docs/COMO_FUNCIONA_EL_DINERO.md)

## Direcciones
| Quién | Dirección |
|---|---|
| Dueño y recepción | `/` (entrar con correo y contraseña) |
| Peluquero | `/` con el acceso que le crea el dueño → su portal |
| Clientes (reservas) | `/?r=reservar&s=<nombre-del-salón>` |
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

## Tareas automáticas y respaldo (en el servidor)
Escribir `crontab -e` y pegar estas dos líneas al final:

```
5 * * * * php /var/www/tusalon/bin/tareas.php >> /var/log/tusalon-tareas.log 2>&1
15 3 * * * /var/www/tusalon/deploy/respaldo.sh >> /var/log/tusalon-respaldo.log 2>&1
```

- Cada hora: recordatorios de mañana (desde las 9:00), cumpleaños (desde las 8:00), pedir calificación, reporte del mes (día 1 desde las 7:00). Cada cosa se envía una sola vez.
- Cada día a las 3:15: copia de la base y de las fotos en `/var/backups/tusalon` (solo legible por el servidor). Para restaurar: `pg_restore -d tusalon --clean archivo.dump`.

## Correr las pruebas
Solo en una base de prueba (el script la borra y la vuelve a crear):

```bash
# Lógica del dinero, agenda y solicitudes
TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/run_tests.php
# Pantallas de punta a punta (con el sitio corriendo en BASE y una base de prueba vacía en DB)
BASE=http://127.0.0.1:8099 DB=tusalon_web_prueba bash tests/prueba_web.sh
```
