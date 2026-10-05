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

## Tecnología
PHP 8.3+ y PostgreSQL 16.

## Estructura
| Carpeta | Qué tiene |
|---|---|
| `db/schema.sql` | Todas las tablas |
| `src/` | La lógica: planes, profesionales, liquidación, rol de pagos y facturas recibidas (gastos) |
| `tests/run_tests.php` | Pruebas con una barbería de ejemplo |

## Correr las pruebas
Solo en una base de prueba (el script la borra y la vuelve a crear):

```bash
# Lógica del dinero, agenda y solicitudes
TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/run_tests.php
# Pantallas de punta a punta (con el sitio corriendo en BASE y una base de prueba vacía en DB)
BASE=http://127.0.0.1:8099 DB=tusalon_web_prueba bash tests/prueba_web.sh
```
