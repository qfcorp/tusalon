# TuSalón

Software para peluquerías y barberías de Ecuador, de la marca Tukán Software.
Dirección: **tusalon.tukanec.com** (servidor qfcorp). Web de la marca: tukansoftware.com.

Un mismo local puede tener 4 tipos de peluquero a la vez: dueño que atiende, empleado (sueldo y/o comisión), porcentaje (50/50, 60/40) y alquiler de puesto.

**Todo es por módulos** y **todo se pide demo**:
- Cada salón tiene la **base** (agenda, clientes, servicios, equipo con los 4 tipos, cuentas del equipo, cobros y caja, horarios, hasta 3 personas) y los **módulos** que contrate. Cada módulo se enciende o se apaga por salón y su precio es por cotización; **apagar nunca borra datos**.
- **Administradores (21 oct 2026):** el dueño puede crear **hasta 4 administradores** (rol `admin`) con su mismo nivel en todo el programa. Son cuentas de acceso: no atienden citas, **no cuentan en el límite de personas** y no dependen de ningún módulo. Se crean en Más → **Administradores** (`/?r=administradores`, también desde Equipo). Lo único exclusivo del dueño es su cuenta: nadie más la cambia ni la desactiva (es la identidad del salón ante Tukán).
- No hay registro abierto ni periodo de prueba: el formulario público es **Solicitar demo**. Tukán prepara la demo (con todos los módulos o los que elija) desde el Panel Tukán Central o desde su propio panel.

### Módulos y planes
**Precios: por cotización (se definen en el Panel Tukán Central).** Nunca se publican; el salón pide cotización.

| Módulo | Necesita |
|---|---|
| 🗓️ Reservas en línea (enlace propio, cuenta del cliente, cambiar la hora, fotos) | — |
| 💳 Anticipo en línea (transferencia/DeUna o Payphone) | Reservas en línea |
| 🎨 Ficha técnica de color | — |
| 🔁 Clientes por recuperar | — |
| 👥 Personas ilimitadas (sin él: hasta 3) | — |
| 📱 Portal y app del peluquero | — |
| 🔒 Clientes privados de quien alquila | Portal del peluquero |
| 🛎️ Cuenta de recepción | — |
| 📦 Inventario y venta de productos | — |
| 🎁 Paquetes y tarjetas de regalo | — |
| ⭐ Puntos para clientes fieles | — |
| 🧾 Gastos con facturas del SRI | — |
| 📊 Reporte del mes | — |
| 📲 Avisos automáticos por Telegram | — |
| ✨ **Asistente IA** (incluido en Completa; en Básica, extra que no cuenta entre los 3 a elegir) | — |

- **Ayuda** («¿En qué le ayudamos?», 21 oct 2026) para **todos los planes**: el dueño, los administradores, recepción y los peluqueros preguntan cómo hacer algo y les responde con los pasos del manual (preguntas frecuentes guardadas → IA con el manual → el manual tal cual). Sin el módulo: hasta 30 respuestas nuevas con IA al mes por salón (las ya guardadas no cuentan). Nunca dice «límite», «cupo» ni «saldo».
- **Asistente IA** (módulo `asistente_ia`, incluido en Completa desde el 21 oct 2026; en Básica, extra que no cuenta entre los 3 a elegir): **Agendar a partir de un mensaje** (se pega el WhatsApp del cliente y propone servicio, peluquero, día y una hora libre de la agenda real; es un borrador que se confirma en Nueva cita) y **Mensaje para clientes que no vuelven**. Si la IA no se puede usar en ese momento, sale «En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano aquí: …».

Planes (28 oct 2026): **Básica** = la base + **3 módulos que el salón elige** (cada uno más se suma) · **Completa** = todos los módulos, con el Asistente IA. Precio de cada salón = plan + módulos extra (los 3 elegidos van incluidos) − descuento de los que quitó (o un **precio acordado** fijo); lo ve y lo cambia solo Tukán (Panel Tukán → Planes y precios, o el Panel Tukán Central). Semestral paga 5 y recibe 6; anual paga 10, recibe 12 (meses de regalo) y trae dominio propio.

- **Manual para los clientes: [docs/Manual_TuSalon.pdf](docs/Manual_TuSalon.pdf)** (cómo se arma y se regenera: [docs/MANUAL.md](docs/MANUAL.md))
- Plan y avance del proyecto: [BLOC_DE_NOTAS.md](BLOC_DE_NOTAS.md)
- Cómo se reparte el dinero: [docs/COMO_FUNCIONA_EL_DINERO.md](docs/COMO_FUNCIONA_EL_DINERO.md)

## Direcciones
| Quién | Dirección |
|---|---|
| Dueño, administradores y recepción | `/` (entrar con correo y contraseña; los administradores los crea el dueño en Más → Administradores, hasta 4; la cuenta de recepción en Más → Recepción, módulo «Cuenta de recepción») |
| Quien alquila el puesto | Su portal → **Mis clientes** (módulo «Clientes privados de quien alquila») |
| Quien quiere TuSalón | `/?r=registro` → **Solicitar demo** (nombre, salón, ciudad, WhatsApp, correo, qué necesita y módulos de interés). No crea el salón: llega a Tukán como pedido de demo |
| Dueño: su plan | Más → **Mi plan** (`/?r=completa`): sus módulos, lo que paga por su plan y los que puede agregar («Precio por cotización» y «Pedir cotización», que llega al Panel Tukán Central como `cambio_modulos`) |
| Peluquero | `/` con el acceso que le crea el dueño → su portal |
| Clientes (reservas) | `/?r=reservar&s=<nombre-del-salón>` |
| Tukán (super administrador) | `/?r=admin_entrar` · cuenta creada con `php bin/crear_admin.php` en el servidor · o con un clic desde el Panel Tukán Central |
| Ayuda (todos) | Menú **Ayuda** (`/?r=ayuda`; en el celular: Más → Ayuda) |
| Asistente IA (módulo) | Menú **✨ Asistente IA** (`/?r=asistente`) · también **✨ Mensaje con IA** en Avisos a clientes |
| Tukán: preguntas de la Ayuda | Panel Tukán → **Preguntas frecuentes (IA)** (`/?r=admin_faq`): aprobar, editar, borrar y agregar a mano |
| Tukán: demo por correo | Panel Tukán → Nueva demo → en la ficha, **Enviar por correo** (desde info@tukansoftware.com) |
| Panel Tukán Central (conector) | v1: `/tukan_central.php?accion=sso&t=…` (entrar, con enlace directo `ir`) · `/tukan_central.php?accion=estado&t=…` (cifras en JSON) · v2: `POST /tukan_central.php?accion=<capacidades, clientes, cliente, cotizar, planes, solicitudes, solicitud, finanzas, ejecutar>` |
| Cliente (su cita: confirmar, cambiar, cancelar, calificar) | `/?r=confirmar&t=<enlace secreto>` (le llega por WhatsApp o Telegram) |

## Tecnología
PHP 8.3+ y PostgreSQL 16.

## Estructura
| Carpeta | Qué tiene |
|---|---|
| `db/schema.sql` | Todas las tablas |
| `src/` | La lógica: planes, profesionales, liquidación, facturas recibidas (lector del SRI en `Sri.php`), agenda, avisos, Telegram, tareas automáticas y calificaciones |
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
- Cifras que ve el panel central: salones por estado (con la regla única de todos los programas: "por vencer" = al día y vence en 7 días o menos; los que están en días de gracia cuentan como vencidos), nuevos en 30 días, cobrado del mes y del mes anterior, demos que vencen en 7 días, pagos vencidos, citas del mes, clientes, peluqueros y usuarios. Sin datos personales (solo nombres de salones).
- Seguridad: cada enlace dura 60 segundos y sirve una sola vez (30 s las operaciones); máximo 120 pedidos y 30 rechazados por minuto por IP. Protocolo: Tukán Central v1 y v2 (`PROTOCOLO.md` y `PROTOCOLO_V2.md` del panel central, iguales en todos los programas Tukán).

### Qué puede hacer el Panel Tukán Central (protocolo v2)
Con el mismo ID y el mismo secreto, el panel central ve y maneja a los salones sin entrar al panel de TuSalón:
- **Clientes**: todos los salones con su estado (en demo, al día, por vencer en 7 días, vencido —incluye los días de gracia— y suspendido/cancelado), plan, vencimiento, dueño, último uso (citas y ventas), pagos y lo que vale al mes. La ficha trae los pagos (también los anulados y los de Google Play), las últimas acciones, las notas internas y los mensajes de WhatsApp listos.
- **Solicitudes**: **pedidos de demo** del formulario «Solicitar demo» (`demo_pedida`: crear la demo con sus datos, marcar contactado o descartar), **pedidos de módulos** desde Mi plan (`cambio_modulos`: aprobar = `cambiar_modulos`, o rechazar), pagos automáticos de Google Play (30 días, para marcar "visto"), demos que terminan en 2 días / hoy / terminadas sin pagar (60 días), planes que vencen en 5 días o vencidos sin renovar (30 días) y dominios del pago anual por registrar. Las reservas que piden los clientes de cada salón son del salón y no se mandan.
- **Operaciones** (con las mismas funciones del panel de Tukán y una línea en "Últimos movimientos del panel" con el correo del Central): crear demo (con plan y módulos: todos, los elegidos o «ninguno»; 5 servicios y el horario; la contraseña se muestra una sola vez; desde un pedido de demo lo deja «demo creada»), alargar la demo, registrar pago (con la fecha en que pagó), anular pago, suspender, reactivar, cancelar, cambiar plan, nueva clave del dueño, nota interna, atender solicitud, guardar plan/precios y, del Anexo B, **cambiar_modulos** (encender/apagar módulos de un salón y precio acordado) y **guardar_modulos** (precios y descripciones del catálogo y qué módulos trae cada plan).
- **Módulos** (Anexo B, `capacidades.revision` = 3): la acción `modulos` da la base, el catálogo y los planes; cada cliente trae `modulos` (`del_plan`, `extra`, `quitados`, `activos`, precios y `mensual`) y `mensual_estimado` = lo que vale al mes con sus módulos (si está pagando).
- **Finanzas**: cobrado por mes (por plan, tiempo y forma de pago), nuevos pagadores, devengado, ingreso mensual recurrente (suma de lo que vale al mes cada salón que paga), `por_modulo` (ingreso estimado de cada módulo extra) y la comisión estimada de Google Play (15 %).
- Si el Central repite una operación (se cortó la red), TuSalón no la hace dos veces: devuelve el mismo resultado (`tukan_central_operaciones`, 90 días, sin contraseñas).
- **Pausar y borrar salones** (Anexo A del protocolo):
  - `pausar` (tipo: falta de pago, pedido del cliente, revisión, uso indebido u otro + motivo): el salón no puede trabajar y ve "Tu cuenta está pausada" con el motivo (en revisión y uso indebido, un texto genérico) y el WhatsApp de Tukán; su página de reservas, las horas libres, la cuenta de sus clientes y el enlace de cada cita dicen "temporalmente no disponible". `reactivar` la quita.
  - `borrar_cliente` (escribir el nombre exacto del salón o su número + motivo; solo el rol dueño del Central): guarda una **copia completa** (todas las filas de todas las tablas del salón, sacadas del esquema, y la lista de sus fotos y archivos) en `respaldos/borrados/<id>_<fecha>.json.gz` (fuera de la web, carpeta 700 de `www-data`; otra carpeta con `TUSALON_BORRADOS`) y lo manda a la **papelera**: no entra, no sale en listas ni cifras (`estado`, `clientes`, `finanzas` desde ese día), sus páginas dan 404 y no recibe tareas, avisos ni renovaciones de Google Play.
  - `restaurar_cliente` dentro de 30 días lo devuelve tal cual (también desde el Panel Tukán de TuSalón: Salones → filtro **Papelera** → Restaurar). Pasados 30 días, la tarea de cada hora lo borra de verdad (sus fotos pasan junto a la copia); las copias se borran al año.
  - `clientes` con `filtros.estado = "papelera"` los lista (cada uno con `papelera: {desde, hasta, motivo}`); cada ficha trae los botones que corresponden (`pausar`/`reactivar`, `borrar_cliente` o `restaurar_cliente`).

## IA de Tukán y correo (21 oct 2026)
Las dos piezas son del Panel Tukán Central (`compartido/` del repo `qfcorp/tukan-central`) y se copian sin cambios en `src/`:
`src/tukan_ia.php` (guía `compartido/LEEME_IA.md`) y `src/tukan_correo.php` (guía `compartido/LEEME_CORREO.md`). Si sale una versión nueva, se vuelven a copiar.

- **Configuración (una sola vez, la hace Dimitry en el servidor desde el Panel):** `cd /var/www/tukan-central && git pull && bash deploy/configurar_ia.sh`
  (escribe `/etc/tukan-ia.env`) y `bash deploy/configurar_correo.sh` (escribe `/etc/tukan-correo.env`). TuSalón **no** guarda claves.
  Sin `/etc/tukan-ia.env` la IA queda apagada y la Ayuda responde con las preguntas frecuentes y el manual; sin `/etc/tukan-correo.env` la
  pantalla de la demo muestra «Configure el correo de Tukán». `www-data` debe poder leer los dos archivos (el instalador lo revisa: puntos 9 y 10).
- **Gasto controlado por salón** (tabla `tukan_ia_gasto`, migración 009): tope del mes **$0,50** sin el módulo y **$4,00** con el módulo (venga en Completa o comprado aparte en Básica); una **demo** (estado `prueba`) **$0,50 en total** aunque tenga todos los módulos (al pagar vuelve sola a lo normal); o el
  que ponga Tukán (`salones.ia_tope_usd`: ficha del salón → **IA de Tukán**, o `ejecutar ia_tope` desde el Panel Central). Al llegar al tope, o con
  `TUKAN_IA_PAUSA=1`, no se llama a la IA y el salón no ve ningún aviso. El tope de todo TuSalón va en `TUKAN_IA_TOPE_PROGRAMA_TUSALON`.
- **Panel Tukán Central:** la acción v2 `ia_uso` (Anexo C) le da el gasto del mes por salón, los totales y el resumen de las preguntas
  frecuentes; se ve en **Más → IA**. Cada ficha de cliente ofrece **Tope de IA** (`ia_tope`, campo `tope_usd` tipo dinero).
- **Demo por correo:** en la pantalla de la demo recién creada (contraseña, WhatsApp) está **Enviar por correo**. La contraseña no se guarda: viaja
  cifrada en el formulario y la llave de un solo uso queda en la sesión (30 min); se anota «Envió la demo por correo a …» sin la contraseña.

## Tareas automáticas y respaldo (en el servidor)
El instalador ya las deja programadas. Son estas dos líneas de cron:
```
5 * * * * php /var/www/tusalon/bin/tareas.php >> /var/log/tusalon-tareas.log 2>&1     (en el crontab de www-data)
15 3 * * * /var/www/tusalon/deploy/respaldo.sh >> /var/log/tusalon-respaldo.log 2>&1
```

- Cada hora: recordatorios de mañana (desde las 9:00), cumpleaños (desde las 8:00), pedir calificación, reporte del mes (día 1 desde las 7:00) y la **papelera** (borra de verdad los salones con más de 30 días en ella y las copias de más de 1 año). Cada cosa se envía una sola vez. Los salones pausados o en la papelera no reciben tareas. Cada tarea es de su módulo: el recordatorio, el pedido de calificación y el saludo por Telegram solo con «Avisos automáticos por Telegram» (sin él quedan con WhatsApp de un toque); el reporte del día 1 solo con «Reporte del mes».
- **Al actualizar a esta versión** hay que volver a correr el instalador: crea `respaldos/borrados` y pasa las tareas al usuario `www-data` (así pueden borrar las copias viejas).
- Cada día a las 3:15: copia de la base y de las fotos en `/var/backups/tusalon` (solo legible por el servidor). Para restaurar: `pg_restore -d tusalon --clean archivo.dump`.

## Correr las pruebas
Solo en una base de prueba (el script la borra y la vuelve a crear):

```bash
# Lógica (1080 pruebas: run_tests.php + prueba_central.php + prueba_central_v2.php + prueba_gastos_recepcion.php + prueba_papelera.php
# + prueba_modulos.php + prueba_administradores.php + prueba_ia.php, con Telegram simulado en el puerto 8098; otro puerto con TG_PUERTO=…;
# prueba_ia.php levanta sola la API de Anthropic y el SMTP DE MENTIRA de tests/falsos/ en puertos libres)
bash tests/run_logica.sh
# Pantallas de punta a punta (856 pruebas: prueba_web.sh + prueba_web_gastos_recepcion.sh + prueba_web_administradores.sh + prueba_web_central.sh + prueba_web_central_v2.sh
# + prueba_web_papelera.sh + prueba_web_modulos.sh + prueba_web_ia.sh; levanta solo los sitios de prueba, Payphone y Telegram simulados, y la API de
# Anthropic y el SMTP de mentira de tests/falsos/).
# Opcional: DB=<base> PUERTO=<puerto>, y CAPTURAS=<carpeta> para sacar las capturas de Mi plan, "No está en tu plan" y los
# interruptores del panel a 390 px y en computadora (Playwright con Chromium ya instalado; no corre "playwright install")
PGPASSWORD=prueba bash tests/run_web.sh
# Solo el conector con el Panel Tukán Central (v1, v2 y pausar/papelera)
TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_central.php
TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_central_v2.php
TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_papelera.php
PGPASSWORD=prueba bash tests/run_web.sh central
```
Las pruebas de siempre se pueden seguir corriendo a mano como antes (`php tests/run_tests.php` y `bash tests/prueba_web.sh` con el sitio corriendo).
