# Manual de TuSalón

El manual para los clientes (dueños de peluquerías y barberías) está en **[docs/Manual_TuSalon.pdf](Manual_TuSalon.pdf)**.
Su fuente es [docs/manual/manual.html](manual/manual.html) y las capturas reales están en [docs/manual/capturas/](manual/capturas/).

## Qué cubre
1. Qué es TuSalón
2. Entrar e inicio (Solicitar demo, Entrar, la pantalla Hoy y el menú)
3. Quién entra: dueño, administradores (hasta 4, mismo nivel que el dueño), peluquero y recepción
4. Los 4 tipos de peluquero (dueño, empleado, porcentaje y alquiler de puesto)
5. Planes y módulos (la base, los 14 módulos, Básica con 3 módulos que tú eliges y Completa con todos y el Asistente IA; en Básica el Asistente IA es extra; los valores son por cotización)
6. Configuración (horario, reglas de las citas en línea, anticipo y Telegram)
7. Servicios y precios del salón
8. Personal y comisiones (agregar peluqueros, accesos, horarios y cuentas del equipo)
9. Agenda y citas
10. Citas en línea (solicitudes, lo que ve el cliente y el anticipo)
11. Cobro y caja
12. Clientes, inventario, paquetes y reportes (ficha técnica, avisos, tarjetas de regalo, puntos y reporte del mes)
13. Mi plan («Pedir cotización»)
    - **Pagar tu plan con tarjeta** (10 nov 2026, en el capítulo de términos y cancelar el plan): si en Mi plan aparece «Paga tu plan con tarjeta»,
      Si después agrega módulos, la suscripción se ajusta sola (valor nuevo desde el próximo cobro y lo proporcional aparte); no se quitan módulos
      contratados ni se baja de plan durante el período pagado.
      Aviso «te sugerimos el plan más completo» con el botón «Pasarme al Completo» cuando con sus módulos paga igual o más (o le falta poco). Siempre se pasa pagando: sin pago con tarjeta disponible el plan no cambia y sale el WhatsApp de Tukán.
      Subir de plan con el período ya pagado y sin suscripción: Mi plan muestra el cálculo (días que faltan y diferencia con IVA) con «Pagar con tarjeta»
      (cambia al aprobarse el pago, el vencimiento no cambia) o «Enviar por WhatsApp» para pagar por transferencia. Si la diferencia es mínima (o ya pagas igual o más que el plan nuevo) no se cobra: toca «Pasarme ahora (no se cobra)».
      el dueño elige plan y período (mensual, 6 meses pagando 5, 1 año pagando 10) y paga solo; suscripción que se cobra sola o pago de una vez. Sin precios en el manual.
14. **Ayuda e Inteligencia artificial** (21 oct 2026): la Ayuda «¿En qué le ayudamos?» para todos los planes y el módulo
    «Asistente IA» (incluido en Completa; extra en Básica) (agendar a partir de un mensaje de WhatsApp con una hora libre de la agenda real y mensaje para
    clientes que no vuelven). Es la fuente de la Ayuda: `src/Ia.php` lee `docs/manual/manual.html` convertido a texto.
15. Preguntas frecuentes

Contacto que lleva el manual: WhatsApp 098 366 2181 · info@tukansoftware.com · tukansoftware.com. Grupo de ayuda en Telegram: https://t.me/+dQfU0W9yvo85MzRh

## Reglas del manual
- Nunca se ofrece nada sin costo ni periodo de prueba: solo **demos**.
- Ningún precio del software (ni de los planes ni de los módulos), tampoco en las capturas: en «Mi plan» se ocultan «Lo que pagas al mes» y el pago con Google Play antes de la foto. Los precios de los servicios del salón sí aparecen (son del cliente).
- Todo se explica por módulos: cada capítulo dice si es de la base o de qué módulo.
- Cada botón o menú que nombra el manual existe en el código (`app/vistas/`).

## Cómo regenerarlo
```bash
bash docs/manual/generar/generar.sh            # base de ejemplo nueva, capturas y PDF
SOLO_PDF=1 bash docs/manual/generar/generar.sh # solo vuelve a armar el PDF con las capturas que ya hay
```
Necesita PostgreSQL local (usuario `tusalon`, clave `prueba`, que pueda crear bases), PHP, Python 3 con `playwright` y `pypdf`,
`pdftotext`/`pdfinfo` (poppler) y el Chromium de Playwright en `/opt/pw-browsers` (nunca se corre `playwright install`).

| Archivo | Qué hace |
|---|---|
| `docs/manual/generar/generar.sh` | Todo el proceso: base `tusalon_manual_prueba` nueva, servidor de prueba, capturas, PDF y revisión. Al final apaga el servidor y borra la base |
| `docs/manual/generar/demo.php` | El salón de ejemplo «Salón Demo Tukán» (plan Completa) con su equipo de 4 tipos, dos administradores (uno sin acceso), un mes de cobros, citas, solicitudes en línea, anticipo, inventario, paquetes, tarjetas, puntos y ficha técnica; más «Barbería Solo Base» (Básica) y «Peluquería en Demo». El salón de ejemplo tiene el módulo «Asistente IA» y una pregunta frecuente escrita a mano: las capturas se toman SIN IA (`TUKAN_IA_ENV` apunta a un archivo que no existe) y la Ayuda responde con esa pregunta |
| `docs/manual/generar/capturas.py` | 44 capturas reales (celular 390 px y computadora 1280 px; la 42 es Menú → Administradores, la 43 la Ayuda y la 44 el Asistente IA) como dueña, recepción, peluquero y cliente. Falla si en una captura se ve un precio del software o la palabra prohibida |
| `docs/manual/generar/pdf.py` | Convierte `manual.html` en el PDF A4 con portada, índice con páginas y pie |
| `docs/manual/generar/revisar.py` | Revisa el HTML, el PDF y este archivo: falla si aparece la palabra prohibida o un precio del software |
| `docs/manual/generar/imagenes/` | Fotos de ejemplo para la ficha técnica y el comprobante del anticipo |

## Ayuda e Inteligencia artificial (capítulo 14)
- La **Ayuda** responde con el texto de `docs/manual/manual.html` (convertido con `tukan_ia_html_a_texto()`): cada capítulo nuevo del
  manual sirve solo para la Ayuda. Por eso el manual debe nombrar los botones y menús tal como están en las pantallas.
- En la Ayuda nunca aparecen «límite», «cupo» ni «saldo»: si el manual los usa (por ejemplo «Ver saldo» de las tarjetas de
  regalo), `Ia::sinPalabras()` los cambia por «máximo», «turno» y «monto» al mostrar la respuesta.

## Factura electrónica con TuFactura (módulo aparte, viene apagado)
*9 nov 2026*

Con este módulo, cada venta cobrada sale como **factura electrónica autorizada por el SRI** y le llega al cliente por correo.

**Cómo se consigue**
- **TuFactura cuesta $30 + IVA al año** y este enlace va incluido. En TuSalón el módulo cuesta $0.
- Pídelo a Tukán Software. Tukán crea tu cuenta de TuFactura y enciende el módulo desde el Panel Tukán Central.
- No viene en ningún plan ni en la demo.

**Conectar tu cuenta** (una sola vez, el administrador)
1. En TuFactura, abre **Configuración → Conexiones** y crea una clave para el punto de emisión que vas a usar. La clave empieza con `tf_`.
2. En TuSalón, abre **🧾 Factura electrónica**, pega la clave y toca **Conectar**. La clave se guarda cifrada y no se vuelve a mostrar.
3. Si quieres, marca **Facturar automático al cobrar**. Revisa también si tus precios ya traen IVA y la tarifa (15 %, 5 % o 0 %).

**Facturar**
- **A mano:** toca **Facturar**, en el menú → Dinero → **Factura electrónica**.
- **Sola:** con «automático» encendido, cada cobro sale facturado al momento.
- La factura va a nombre de la cédula o el RUC del cliente. Si no los tiene, va a consumidor final.
- El total es exactamente lo que cobraste. Cuando la cuenta del SRI no lo permite, queda 1 centavo menos.
- El mismo cobro nunca se factura dos veces.
- El PDF autorizado se baja con un toque.


**Si algo falla**
- Si TuFactura o el SRI no responden, el cobro igual se guarda. La factura queda pendiente: toca **Reintentar las pendientes** o **Revisar**.
- Si apagas el módulo, las facturas hechas se quedan en TuFactura.

## Demos automáticas de 3 días (10 nov 2026)
- **Solicitar demo** pide 6 datos, todos obligatorios: nombre y apellido, nombre del negocio, celular (WhatsApp), dirección, ciudad y correo. Un solo teléfono.
- **Preparar la demo toma entre 5 y 10 minutos aproximadamente.** La clave llega al correo cuando está lista, con el manual y la guía de primeros pasos (revisar spam). Por eso el correo debe estar bien escrito.
- **La demo dura 3 días**, llega vacía y con todos los módulos.
- En el **Inicio** de la demo: «Tu demo dura hasta el dd/mm» con **Descargar el manual** y **Descargar los primeros pasos**. Los mismos botones están siempre en **Ayuda**.
