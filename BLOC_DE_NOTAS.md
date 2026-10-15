# Bloc de notas — TuSalón (software de peluquerías y barberías, marca Tukán)

Última actualización: 14 de octubre de 2026

## Qué es
Sistema para peluquerías y barberías de Ecuador. Un mismo local puede tener 4 tipos de peluquero a la vez:
- **Dueño que atiende:** sin comisión ni licencia extra; ve por separado lo que produjo y lo que ganó el local
- **Empleado:** sueldo, comisión o ambos
- **Porcentaje:** 50/50, 60/40, con reparto automático
- **Alquiler de puesto:** cobra con su propio QR; el dueño le carga el arriendo con un botón; sus clientes privados son solo suyos (Completa)

## Decisiones tomadas
- [x] Nombre: TuSalón (sigue el esquema TuPass, TuVocalía)
- [x] Se arranca con los 4 tipos de peluquero desde el inicio
- [x] Tomar lo mejor de cada programa (tabla en el informe, sección "Nuestra propuesta")
- [x] SRI: NO emitimos facturas. Se importan del SRI las facturas que el salón **RECIBE** de sus proveedores (tintes, shampoo, luz, agua, arriendo del local) para mostrar los gastos en el panel del dueño
- [x] Plan Básica $25/mes: muy básico, para empujar a contratar la Completa
- [x] Plan Completa $35/mes: todo incluido
- [x] Semestral: paga 5, recibe 6 (Básica $125, Completa $175) — sale a $20,83 y $29,17 al mes
- [x] Anual: paga 10, recibe 12 (Básica $250, Completa $350) + dominio propio .com del salón mientras dure el contrato (~$10,46/año en Cloudflare)
- [x] Ahorro en ambos casos: 16,7 %. El dominio es lo que hace que el anual convenga más que el semestral
- [x] El dominio se registra a nombre de Tukán (si el salón no renueva, se le puede transferir cobrando el año)
- [x] Prueba gratis: 7 días
- [x] Servidor provisional: **tusalon.qfradioec.com** en el servidor qfcorp (PHP 8.3+ y PostgreSQL)

## Planes
| Función | Básica $25 | Completa $35 |
|---|---|---|
| Agenda | Hasta 3 personas | Ilimitado (una sola sucursal) |
| 4 tipos de peluquero | Cálculo simple | Completo |
| Ficha del cliente | Básica | Ficha técnica de color con fotos |
| Caja diaria | Sí | Sí |
| WhatsApp | Con un clic | Con un clic + avisos automáticos por Telegram (WhatsApp automático: pendiente) |
| Reservas en línea, anticipo en línea (transferencia/DeUna con comprobante o tarjeta con Payphone) | No | Sí |
| Reparto del porcentaje, comisiones de producto, cuentas del equipo | Cálculo simple | Sí |
| Clientes privados del arrendatario, vista doble del dueño | No | Sí |
| Cuenta de recepción (sin ver ganancias) | No | Sí |
| App del peluquero, inventario, fidelidad | No | Sí |
| Importar facturas recibidas del SRI (XML, TXT o clave) y ganancia real | No | Sí |
| Reportes | Del día | Rentabilidad por servicio y peluquero |

## Decisión del 14 oct 2026: nada que no exista en los textos de venta
- QUITADO para siempre de Mi plan, planes y registro: **rol de pagos con IESS y décimos** y **cobro automático del arriendo**. No se venden. (La lógica vieja sigue en `src/Liquidacion.php` sin pantalla; el arriendo se carga con el botón "Cargar arriendo" en Cuentas.)
- **Varias sucursales:** QUITADO de los textos y queda PENDIENTE (ver abajo).

## Decisiones pendientes
- [ ] Peluquería conocida para la primera prueba gratis

## Hecho
- [x] Investigación de mercado (21 programas mundiales, 15 de Latinoamérica, contexto Ecuador)
- [x] Revisión de precios de Beauty360Pro (competidor ecuatoriano): $14 / $29 / $49 al mes, solo mensual, sin descuento anual, 15 días de prueba, cobra con Payphone
- [x] Definición de planes y precios
- [x] Base de datos completa (`db/schema.sql`): salones, planes, suscripciones, 4 tipos de peluquero, reglas de pago, escalas, clientes privados, ficha técnica, citas, ventas, cuenta corriente, liquidaciones, caja, facturas recibidas de proveedores
- [x] Lógica del dinero (`src/`): reparto por tipo, propinas, arriendo, anticipos, escalas, rol de pagos con IESS, liquidación, vista doble del dueño
- [x] Prueba simulada "Barbería Don Pepe": 49 de 49 pruebas correctas. Se metió un error a propósito y las pruebas lo detectaron
- [x] Explicación simple del reparto: `docs/COMO_FUNCIONA_EL_DINERO.md`
- [x] Corrección: las facturas importadas son las que RECIBE el salón (gastos). Tabla `facturas_recibidas` con categoría de gasto y proveedores que recuerdan su categoría; el panel del dueño muestra gastos y ganancia después de gastos. **55 de 55 pruebas correctas**

## Hecho (15 oct 2026) — Pausar y borrar salones desde el Panel Tukán Central (Anexo A)
- [x] **Pausar** (con tipo: falta de pago, pedido del cliente, revisión, uso indebido u otro, y motivo obligatorio): el salón queda suspendido; al entrar ve "Tu cuenta está pausada" con el motivo (en revisión y uso indebido, un texto genérico) y el WhatsApp de Tukán. Su página de reservas y los enlaces de las citas dicen "temporalmente no disponible". **Reactivar** lo quita
- [x] **Borrar** (escribiendo el nombre exacto y el motivo; solo el dueño del Panel Central): primero se guarda una copia completa del salón (todas sus filas y la lista de sus fotos) en `respaldos/borrados/` (fuera de la web), y queda en la **papelera**: no entra, no sale en listas ni cifras, sus páginas dan 404 y no recibe tareas, avisos ni renovaciones de Google Play
- [x] **Restaurar** dentro de 30 días (desde el Panel Central o desde el Panel Tukán de TuSalón, filtro "Papelera"). A los 30 días la tarea de cada hora lo borra de verdad; la copia se guarda 1 año
- [x] Pruebas: `prueba_papelera.php` (86) y `prueba_web_papelera.sh` (55), migración 007. Totales: lógica 893/893 y pantallas 636/636
- [ ] DIMI: al actualizar el servidor, volver a correr el instalador (abajo, "Cómo actualizar el servidor qfcorp"): crea `respaldos/borrados` y pasa las tareas de cada hora a `www-data`

## Cambio de direcciones (6 oct 2026)
- TuSalón ahora está en **tusalon.tukanec.com** (antes tusalon.qfradioec.com, que se apaga). Nombres oficiales: TuSalón y TuGuardería (ya no "Tukán Salón" ni "Tukán Pet")
- Se quitó la promesa de "WhatsApp automático" del registro y de la página de planes (todavía no existe)
- TuGuardería (repo qfcorp/tuguarderia): direcciones cambiadas a tuguarderia.tukanec.com y dogssy.tukanec.com en README, LEEME e instalar.sh
- HECHO (12 oct 2026): web tukansoftware.com actualizada (TuGuardería, TuSalón como 4.º sistema disponible con botón "Prueba gratis 7 días" al registro). Código en GitHub qfcorp/tukan-web
- HECHO (12 oct 2026): servidor actualizado y rutas del túnel con su propio Host header; rutas viejas .qfradioec.com de los 3 programas quitadas
- HECHO (12 oct 2026): se construyeron las funciones que la página de planes prometía y faltaban (ver 3g)

## Plan de trabajo
1. [x] Repositorio qfcorp/tusalon en GitHub con este bloc de notas
2. [x] Base de datos con los 4 tipos de peluquero + prueba en entorno simulado
3. [x] Plan Básica: registro con 7 días gratis, entrada, inicio "Hoy", agenda por sillas, citas, cobros, caja con cierre, clientes, servicios, equipo con los tipos, cuentas del equipo, WhatsApp con un clic, página de planes. Pruebas web 46/46 y lógica 60/60; revisado en capturas de celular y computadora
3a. [x] Pedido del 4 de octubre (antes de instalar):
    - Servicios ilimitados creados por el dueño con **precio al cliente** y **pago al peluquero** (pago fijo para empleados; si se deja vacío, usa su %)
    - El dueño puede **cambiar el precio al agendar**; el peluquero gana igual su pago fijo
    - **Portal de reservas** para clientes (`/?r=reservar&s=<salón>`): elige peluquero, servicio, día y ve las horas libres en tiempo real (se actualiza cada 30 s)
    - **Solicitudes:** la reserva del cliente llega como solicitud. El dueño programa quién la acepta (dueño, peluquero, cualquiera o automático), a quién se avisa (dueño, peluquero o ambos) y en cuánto tiempo se libera si nadie responde
    - Mientras espera, la hora aparece **"en confirmación"**; si otro cliente la intenta, recibe "Estamos confirmando esa hora para otro cliente"
    - Campana de avisos y página de Solicitudes con Aceptar/Rechazar y WhatsApp al cliente con un toque
    - **Portal del peluquero** (acceso que crea el dueño): sus citas, lo que hizo, lo que ganó, su saldo y lo que le toca por cada servicio; no ve la caja ni nada del dueño
    - Probado: lógica 83/83, web 76/76, tres clientes reservando la misma hora al mismo instante (solo uno la obtiene), capturas en celular
    - Portal de reservas y portal del peluquero son del plan **Completa** (en Básica aparecen con candado)
3d. [x] Pedido del 4 de octubre (2):
    - **Avisos por Telegram** con botones Aceptar/Rechazar; cada usuario conecta su Telegram (guía en `docs/TELEGRAM.md`). Si Telegram falla, la reserva se guarda igual
    - **Cuenta opcional del cliente:** reserva como invitado o crea su cuenta; ve próximas citas e historial (`/?r=mi_cuenta&s=<salón>`). Por privacidad, una cuenta no se une a una ficha solo por el celular (sí por correo que el salón ya tenía)
    - **Fotos:** solo si el cliente tiene cuenta y dio permiso; las sube el peluquero que atendió o el dueño; se achican a 1600 px, se borran datos ocultos (GPS) y se guardan fuera de la parte pública; las ve solo el cliente y el salón
    - Probado: lógica 113/113, web 93/93 (con Telegram simulado)
3e. [x] Pedido del 4 de octubre (3): las 9 mejoras + desplegable de servicios + valor al aceptar + cumpleaños
    - **Desplegable de servicios** en las reservas (invitado y con cuenta): todos los servicios que creó el dueño. Si el cliente **deja el mouse 3 segundos** sobre un servicio, se despliega su explicación y cuánto dura (una barrita amarilla avisa que se está abriendo). En el celular se toca **ⓘ**. Al elegirlo, la explicación queda visible abajo
    - Cada servicio tiene **explicación para el cliente** y duración (las pone el dueño al crearlo)
    - **Al aceptar**, el dueño escribe el **valor a cobrar**; al cliente le llega "Valor a cancelar: $X" por WhatsApp (un toque) y por Telegram si lo conectó
    - **Cumpleaños opcional** (solo día y mes, sin año) al crear la cuenta, en "Mi cuenta" y en la ficha del cliente. Los del 29 de febrero se saludan el 28 en años no bisiestos
    - Mejora 1: **recordatorio el día anterior** con enlace para confirmar o cancelar con un toque (WhatsApp con un toque para todos; Telegram automático con botones en Completa)
    - Mejora 2: el cliente **cancela o cambia** su cita desde su enlace o su cuenta, hasta X horas antes (lo pone el dueño: 0 a 48 h)
    - Mejora 3: **horario propio por peluquero**, almuerzo, **vacaciones y permisos** (Equipo → Horario, almuerzo y vacaciones)
    - Mejora 4: servicios que **solo hacen algunos** peluqueros y **precio distinto por peluquero**
    - Mejora 5: **faltas**: tras N faltas (lo pone el dueño) no puede reservar en línea; el dueño las puede **perdonar** en la ficha
    - Mejora 6: **clientes por recuperar** ("hace 8 semanas no viene") con WhatsApp listo (Completa)
    - Mejora 7: **calificación de 1 a 5 estrellas** tras el servicio; con 4 o 5 se invita a dejar **reseña en Google**
    - Mejora 8: **reporte del mes** (lo más vendido, mejor peluquero, estrellas, horas y día más vacíos, faltas) por Telegram el día 1 y en pantalla (Completa)
    - Mejora 9: **respaldo diario** de la base y fotos (`deploy/respaldo.sh`, guarda 14 días, probado restaurando)
    - Tareas automáticas cada hora: `bin/tareas.php` (cada aviso se envía una sola vez)
    - Nuevas pantallas: Avisos a clientes, Reporte del mes, Horario del peluquero, página de la cita del cliente
    - Probado: **lógica 202/202, web 145/145**, capturas en celular y computadora, respaldo restaurado en otra base
3f. [x] **Panel Tukán (super administrador)** — `/?r=admin_entrar` (sesión aparte de los salones)
    - Resumen: salones por estado, ingreso mensual, cobrado del mes y del año, pruebas que terminan en 3 días y planes que vencen en 15 días (con WhatsApp listo), últimos registrados, historial
    - Salones: buscar y filtrar; ficha con pagos (registrar, precio especial, dominio en el anual, anular), cambiar plan, pausar/reactivar/cancelado, dar días de prueba, nueva contraseña del dueño, notas internas y **entrar como el dueño** para soporte (queda registrado)
    - Nuevo salón creado por Tukán (genera la contraseña y la manda por WhatsApp)
    - Planes y precios: nombre, precio, máximo de personas; días de prueba, promociones semestral/anual, días de gracia, WhatsApp de ventas
    - Vencimiento real: al pasar la fecha pagada + días de gracia, el salón queda bloqueado (y su página de reservas se pausa); en la gracia ve un aviso para renovar
    - Super usuario: se crea en el servidor con `php /var/www/tusalon/bin/crear_admin.php` (la contraseña solo la escribe Dimitry)
    - Migraciones: `db/migraciones/` (el instalador las aplica sin borrar datos)
    - Probado: lógica 227/227, web 175/175, capturas en celular y computadora
3g. [x] **Pedido del 12 de octubre: lo que faltaba del plan Completa** (migración `db/migraciones/002_completa_extras.sql`)
    - **Inventario** (Más → Inventario): productos para vender y de uso del salón (tintes), stock inicial, "llegó mercadería" (con costo y factura), "se usó en el salón", corregir conteo, historial, aviso de stock bajo en el inicio, valor del estante. Al cobrar se venden productos y se descuenta el stock (comisión de producto del peluquero)
    - **Paquetes** (ej. 4 cortes por $50, con vencimiento): se venden a un cliente (entra a la caja ese día) y al cobrar se marca "Usar sesión del paquete" (el peluquero gana su comisión sobre lo que vale cada sesión)
    - **Tarjetas de regalo**: código único tipo AB12-CD34, saldo, para quién, mensaje, vencimiento; se envía por WhatsApp o se imprime; al cobrar se escribe el código y se descuenta (en una o varias visitas); anular si no se usó
    - **Puntos**: el dueño decide cuántos puntos por $1, cuánto valen 100 puntos y desde cuántos se canjean; se ganan solos al cobrar (sobre lo que paga ese día, sin propina) y se canjean como descuento; regalar o quitar puntos con motivo
    - **Ficha técnica de color** (en la ficha del cliente y en el portal del peluquero): trabajo, marca, tono, oxidante, fórmula, tiempo, resultado, notas y fotos de antes y después (solo si el cliente autorizó; solo las ve el personal del salón; se borran datos ocultos). El peluquero ve la ficha de los clientes que atendió o tiene agendados
    - **Anticipo en línea** (Horario y reservas → Anticipo): a todos, solo a clientes nuevos o solo a quienes faltaron; % o valor fijo. El cliente paga por **transferencia/DeUna subiendo la foto del comprobante** (el dueño la revisa y confirma en la cita) o **con tarjeta por Payphone** (se confirma solo; cada salón pone su Token y Store ID). Mientras no paga, la reserva queda como solicitud; pagado, la hora ya no se libera. Entra a la caja el día que se confirma y se descuenta solo al cobrar
    - **Caja**: muestra lo cobrado hoy (sin lo que ya estaba pagado con paquete, tarjeta, anticipo o puntos) y una lista aparte de paquetes, tarjetas y anticipos vendidos ese día
    - **App del peluquero**: TuSalón se instala en el celular desde el navegador (ícono propio, pantalla completa, aviso si no hay internet). Botón "Instalar" en el portal del peluquero y en el inicio del dueño. Sin Play Store
    - Todo es del plan Completa; en Básica aparece con candado
    - Probado: **lógica 284/284, web 215/215** (con Payphone simulado), migración probada sobre la base vieja, capturas en celular y computadora
    - **Manual en PDF** (48 páginas, capturas reales con números): `docs/Manual_TuSalon.pdf`; capturas nuevas en `docs/manual/21_…` a `33_…`
    - **Presentación de ventas** (13 diapositivas, identidad Tukán, capturas reales): artifact "TuSalón — Presentación de ventas" en Claude (se descarga como PowerPoint o PDF)
    - PENDIENTE: probar Payphone con claves reales de un salón (la cajita de pagos y la confirmación están hechas según la documentación de Payphone)
3b. [x] **TuSalón en línea: https://tusalon.qfradioec.com** (4 oct, 23:20). Instalación en qfcorp (4 oct, 23:08): **hecha** con el instalador de una línea (`deploy/instalar.sh`): base PostgreSQL 18 con 29 tablas, nginx, PHP 8.5, tareas cada hora y respaldo diario 3:15 funcionando (prueba local 200)
    - [x] Cloudflare: ruta `tusalon.qfradioec.com` → `http://localhost:80` agregada en el túnel **servidorqf** (Redes → Conectores → servidorqf → Rutas de aplicaciones publicadas). El config.yml del servidor es solo una plantilla; el túnel se maneja desde la web
    - [ ] Falta: crear el bot de Telegram (docs/TELEGRAM.md)
    - [x] **Manual para clientes** terminado: documento "Manual de TuSalón" (en Claude, se puede exportar a PDF o Word) con 28 capturas marcadas con números en `docs/manual/`. Explica registro, pantalla, servicios, equipo, horarios, citas, solicitudes, cobro y caja, clientes, reservas del cliente, avisos, Telegram, portal del peluquero, cuentas, reporte, planes y preguntas frecuentes
    - [ ] Decidir si el repositorio de GitHub pasa a privado (hoy es público)
    - Para actualizar el servidor: la misma línea del instalador (no borra datos). Ver "Cómo actualizar el servidor" al final
3h. [x] **Apps de Android para Google Play (12 oct)** — listas para subir, TODAVÍA NO publicadas (Dimi está analizando precios)
    - Apps en el repositorio privado **qfcorp/tukan-apps** (carpeta `salidas/`): TuSalón y TuGuardería, cada una con `.aab` (Google Play) y `.apk` (instalar directo). Guía completa en `LEEME.md` de ese repositorio
    - Compilar desde el celular: GitHub → tukan-apps → Actions → "Compilar app" → Run workflow
    - TuSalón cobra los planes con Google Play (migración 003, `src/GooglePlay.php`, `docs/GOOGLE_PLAY.md`). Precios sugeridos con el 15 % de Google: Básica $29,99/mes, Completa $41,99/mes (semestral $147,99 / $205,99, anual $294,99 / $411,99)
    - **APAGADO en el servidor hasta decidir.** Cada vez que se corre el instalador pregunta "Activar la app de Google Play esta vez? si/no". Mientras se conteste "no", todo funciona como siempre. **Claude: en cada actualización, recordar a Dimi esa pregunta y preguntarle si esta vez se activa**
    - Política de privacidad pública (la pide Google): `?r=privacidad` (TuSalón) y `privacidad.php` (TuGuardería)
    - TuVocalía: su app ya está hecha en su propio repositorio (qfcorp/tuvocalia, rama v38-apps)
    - [ ] TuPass: decidir "app solo para ver" (sin comisión) o "compra dentro con Google" y subir su código a GitHub
    - [ ] Cuando se decida: cuenta personal de Play Console exige prueba cerrada con 12 probadores durante 14 días antes de publicar
    - [ ] Después de la primera subida: agregar la huella SHA-256 de Google al servidor (pasos en las guías)
3i. [x] **Conexión con el Panel Tukán Central (13 oct)** — protocolo Tukán Central v1 (el mismo de TuEvento y los demás)
    - `tukan_central.php`: entrar con un clic desde el panel central (crea el super usuario la primera vez y después usa el mismo; queda en el historial del panel) y cifras para el panel central (salones por estado igual que el panel, nuevos en 30 días, cobrado del mes y del mes anterior, pruebas que vencen en 7 días, pagos vencidos, citas del mes, clientes, peluqueros, usuarios, ingreso mensual)
    - Botón "← Panel Tukán" cuando se entró desde el central; `TUKAN_SOLO_CENTRAL=1` apaga el ingreso local del super usuario (emergencia: ponerlo en 0 y `bin/crear_admin.php`)
    - Cada enlace dura 60 s y sirve una vez, 30 pedidos por minuto por IP, sin datos personales; sin configurar responde 404
    - Comando: `sudo bash /var/www/tusalon/deploy/conectar_central.sh {ID} {SECRETO} {URL}` (guarda en config/.env y hace una prueba local)
    - Migración 004 (también en `db/schema.sql`). Pruebas nuevas: `tests/prueba_central.php` (122) y `tests/prueba_web_central.sh` (158); corredores `tests/run_logica.sh` y `tests/run_web.sh` con Telegram y Payphone simulados (`tests/simulador.php`). Totales: lógica 424/424, pantallas 391/391, sin avisos de PHP
    - [ ] DIMI: cuando el Panel Tukán Central esté listo, actualizar TuSalón (instalador) y correr el comando con los datos que da el panel
3j. [x] **Panel Tukán Central v2 (13 oct)** — protocolo Tukán Central v2 (el mismo de TuEvento y los demás)
    - El panel central ya puede, para TuSalón: ver todos los salones con su estado y vencimiento, abrir su ficha (pagos, últimas acciones, notas, WhatsApp listo), ver las solicitudes (registros nuevos, pagos de Google Play, pruebas por terminar o terminadas, planes por vencer o vencidos, dominios gratis) y marcarlas atendidas, y ver las finanzas por mes (con la comisión estimada de Google Play)
    - Desde el central: crear demos (con 5 servicios y horario; la clave se ve una sola vez), alargar la prueba (no a quien ya paga), registrar pago con la fecha en que pagó, anular pago, suspender, reactivar, cancelar, cambiar plan, nueva clave del dueño, nota interna y cambiar precios de los planes. Si el central reintenta, no se cobra ni se crea dos veces
    - "Por vencer" ahora es lo mismo en todos los programas: al día y vence en 7 días o menos. Los que están en días de gracia cuentan como vencidos (antes TuSalón los contaba como "por vencer")
    - El registro de pagos del panel de Tukán pasó a `PanelTukan::registrarPago()` (lo usan el panel y el central); anular un pago revisa que sea de ese salón; cambiar el estado avisa si el salón no existe
    - Se guarda de dónde vino cada salón (se registró solo, panel de Tukán o demo del central). Entrar desde el central puede abrir directo la ficha de un salón
    - Migración 005 (también en `db/schema.sql`). Pruebas nuevas: `tests/prueba_central_v2.php` (306) y `tests/prueba_web_central_v2.sh` (85). Totales: lógica 735/735, pantallas 476/476, sin avisos de PHP
    - Falta (no existe en TuSalón): pedidos de demo, comprobantes de pago y soporte. Si se quieren en el central, hay que construirlos aquí primero
3k. [x] **Pedido del 14 de octubre: lo que Mi plan prometía sin pantalla** (migración `db/migraciones/006_gastos_recepcion.sql`)
    - **Textos de venta corregidos** (Mi plan, registro, aviso de Cuentas, ayuda de "afiliado al IESS"): ya no dicen rol de pagos, IESS, décimos, cobro automático del arriendo ni sucursales. Ahora venden la cuenta de recepción y los gastos con facturas del SRI
    - **Gastos y facturas** (Más → Gastos y facturas, Completa): se suben las facturas que el salón RECIBE (TuSalón no emite): XML (varios o .zip), reporte TXT de "Comprobantes electrónicos recibidos" de SRI en línea, o claves de acceso pegadas (se bajan del SRI, 15 por vez). Botón "Completar con el SRI" para las del TXT. Categoría por factura (el proveedor la recuerda), quitar facturas personales, aviso si la factura vino a nombre de otro RUC (se guarda el RUC del salón ahí mismo), notas de crédito restan. Ganancia del local, gastos y ganancia real del mes. El inicio del dueño enlaza aquí. Lector igual al de TuVocalía (`src/Sri.php`)
    - **Recepción** (Más → Recepción, Completa): el dueño crea, cambia la clave o quita el acceso. Recepción ve Hoy (sin ganancias), Agenda, Solicitudes (acepta/rechaza como el dueño y le llegan esos avisos), Caja (cobra y cierra) y Clientes. No entra a equipo, cuentas, gastos, reporte, servicios, horario, inventario, paquetes ni Mi plan
    - **Clientes privados de quien alquila** (en su portal → Mis clientes, Completa): los crea, edita, les escribe por WhatsApp y los agenda en su silla. El dueño y recepción ven la hora como "Cliente de Mateo" sin nombre ni celular (agenda, cita, caja, solicitudes, avisos); no salen en Clientes, cumpleaños, por recuperar, puntos ni reservas en línea; la ficha técnica solo la ve él
    - Pruebas: **lógica 807/807, pantallas 581/581** (nuevas: `tests/prueba_gastos_recepcion.php` 72 y `tests/prueba_web_gastos_recepcion.sh` 105, con el SRI simulado), sin avisos de PHP; capturas en celular y computadora
    - PENDIENTE (no se hizo hoy, y por eso se quitó de los textos): **varias sucursales**. Es grande: cada sucursal necesita su propio horario, sus sillas/peluqueros, su caja y cierre, su enlace de reservas, filtros en agenda, reportes y gastos, y decidir cómo se cobra el plan (¿por sucursal?). Hoy la base tiene la tabla `sucursales` y columnas `sucursal_id` sin uso. Hacerlo a medias rompería la caja y las reservas. Proponer a Dimi el precio por sucursal antes de construirlo
    - PENDIENTE: avisar a Dimi que la web tukansoftware.com (repo qfcorp/tukan-web) no promete nada de esto (dice "Comisiones y arriendo de sillas", que sí existe): no hubo que tocarla
3c. [ ] Más adelante: aviso automático por WhatsApp (API oficial de Meta); hoy los avisos son la campana y Telegram
4. [ ] Completa 1: reservas en línea, WhatsApp automático, anticipos con Plux o Payphone
5. [~] Completa 2: reparto del porcentaje y cuentas del equipo HECHOS; rol de pagos y cobro automático del arriendo DESCARTADOS (14 oct)
6. [x] Completa 3: ficha técnica, inventario, fidelidad y app del peluquero (3g); facturas recibidas del SRI, recepción y clientes privados (3k)
7. [ ] Cobro de la suscripción (mensual, semestral, anual) y bloqueo de funciones según el plan
8. [ ] Prueba real en una peluquería, ajustes y lanzamiento en tukansoftware.com
9. [ ] **Después del programa base:** versión con logo para **Daniel Mendoza Asesores de Imagen** (Quito). Datos y lo que falta conseguir en `docs/CLIENTE_DANIEL_MENDOZA.md`. Falta: logo, colores y fotos (Dimitry los baja de su Facebook/Instagram)

## Datos clave
- 21.144 peluquerías/salones con RUC en Ecuador (INEC 2023); 67 % sin empleados afiliados
- Payphone es la única pasarela local que reparte un cobro entre varias cuentas (Split de Pagos)
- Recordatorio por WhatsApp oficial: ~$0,0113 cada uno
- La Completa de $35 queda entre el Profesional ($29) y el Premium ($49) de Beauty360Pro, con más cosas que su Premium (alquiler de silla, porcentaje, WhatsApp automático)
- La Básica de $25 sirve para que la Completa se vea barata (solo $10 más)

## Mejoras propuestas — ya hechas (ver 3e), salvo el anticipo en línea
- [x] Anticipo en línea para clientes nuevos o que faltaron: hecho (3g), con transferencia/DeUna y Payphone

### Lista original
1. Recordatorio automático al cliente el día anterior (WhatsApp o Telegram del cliente) y confirmación con un toque
2. El cliente cancela o cambia su cita desde su cuenta, con reglas del dueño (ej. hasta 2 horas antes)
3. Anticipo en línea para clientes nuevos o que faltaron antes (Payphone/Plux), configurable
4. Lista de clientes que faltan (no asistió) y bloqueo de reserva en línea tras 2 faltas
5. Días libres, vacaciones y almuerzo por peluquero (hoy el horario es del salón)
6. Servicios que solo hacen ciertos peluqueros y precio distinto por peluquero
7. Reseñas después del servicio (1 a 5 estrellas) y enlace a Google
8. Clientes que no vuelven: aviso "hace 6 semanas no viene" con mensaje listo
9. Reporte del mes para el dueño (lo más vendido, mejor peluquero, horas muertas) por Telegram
10. Respaldo automático diario de la base (como el ERP) antes de tener salones pagando

## Cómo actualizar el servidor qfcorp
Pegar en la sesión SSH, una línea a la vez (no borra datos):
1. `curl -fsSL https://raw.githubusercontent.com/qfcorp/tusalon/main/deploy/instalar.sh -o ~/instalar_tusalon.sh && bash ~/instalar_tusalon.sh`
   - Pregunta "Activar la app de Google Play esta vez?": contestar `no` mientras Dimi no decida publicar
   - Debe mostrar las migraciones con "listo" (incluidas `006_gastos_recepcion.sql` y `007_papelera.sql`) y al final: tablas más de 25, extensiones 6, prueba de la página 200
2. `curl -s -o /dev/null -w "%{http_code}\n" -H "Host: tusalon.tukanec.com" "http://127.0.0.1/?r=login"` → debe salir 200
