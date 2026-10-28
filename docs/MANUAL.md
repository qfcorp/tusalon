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
14. **Ayuda e Inteligencia artificial** (21 oct 2026): la Ayuda «¿En qué le ayudamos?» para todos los planes y el módulo
    «Asistente IA» (incluido en Completa; extra en Básica) (agendar a partir de un mensaje de WhatsApp con una hora libre de la agenda real y mensaje para
    clientes que no vuelven). Es la fuente de la Ayuda: `src/Ia.php` lee `docs/manual/manual.html` convertido a texto.
15. Preguntas frecuentes

Contacto que lleva el manual: WhatsApp 099 640 8397 · info@tukansoftware.com · tukansoftware.com.

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
| `docs/manual/generar/capturas.py` | 44 capturas reales (celular 390 px y computadora 1280 px; la 42 es Más → Administradores, la 43 la Ayuda y la 44 el Asistente IA) como dueña, recepción, peluquero y cliente. Falla si en una captura se ve un precio del software o la palabra prohibida |
| `docs/manual/generar/pdf.py` | Convierte `manual.html` en el PDF A4 con portada, índice con páginas y pie |
| `docs/manual/generar/revisar.py` | Revisa el HTML, el PDF y este archivo: falla si aparece la palabra prohibida o un precio del software |
| `docs/manual/generar/imagenes/` | Fotos de ejemplo para la ficha técnica y el comprobante del anticipo |

## Ayuda e Inteligencia artificial (capítulo 14)
- La **Ayuda** responde con el texto de `docs/manual/manual.html` (convertido con `tukan_ia_html_a_texto()`): cada capítulo nuevo del
  manual sirve solo para la Ayuda. Por eso el manual debe nombrar los botones y menús tal como están en las pantallas.
- En la Ayuda nunca aparecen «límite», «cupo» ni «saldo»: si el manual los usa (por ejemplo «Ver saldo» de las tarjetas de
  regalo), `Ia::sinPalabras()` los cambia por «máximo», «turno» y «monto» al mostrar la respuesta.

