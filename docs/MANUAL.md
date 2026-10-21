# Manual de TuSalón

El manual para los clientes (dueños de peluquerías y barberías) está en **[docs/Manual_TuSalon.pdf](Manual_TuSalon.pdf)**.
Su fuente es [docs/manual/manual.html](manual/manual.html) y las capturas reales están en [docs/manual/capturas/](manual/capturas/).

## Qué cubre
1. Qué es TuSalón
2. Entrar e inicio (Solicitar demo, Entrar, la pantalla Hoy y el menú)
3. Quién entra: dueño, administradores (hasta 4, mismo nivel que el dueño), peluquero y recepción
4. Los 4 tipos de peluquero (dueño, empleado, porcentaje y alquiler de puesto)
5. Planes y módulos (la base, los 14 módulos, Básica y Completa; los valores son por cotización)
6. Configuración (horario, reglas de las citas en línea, anticipo y Telegram)
7. Servicios y precios del salón
8. Personal y comisiones (agregar peluqueros, accesos, horarios y cuentas del equipo)
9. Agenda y citas
10. Citas en línea (solicitudes, lo que ve el cliente y el anticipo)
11. Cobro y caja
12. Clientes, inventario, paquetes y reportes (ficha técnica, avisos, tarjetas de regalo, puntos y reporte del mes)
13. Mi plan («Pedir cotización»)
14. Preguntas frecuentes

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
| `docs/manual/generar/demo.php` | El salón de ejemplo «Salón Demo Tukán» (plan Completa) con su equipo de 4 tipos, dos administradores (uno sin acceso), un mes de cobros, citas, solicitudes en línea, anticipo, inventario, paquetes, tarjetas, puntos y ficha técnica; más «Barbería Solo Base» (Básica) y «Peluquería en Demo» |
| `docs/manual/generar/capturas.py` | 42 capturas reales (celular 390 px y computadora 1280 px; la 42 es Más → Administradores) como dueña, recepción, peluquero y cliente. Falla si en una captura se ve un precio del software o la palabra prohibida |
| `docs/manual/generar/pdf.py` | Convierte `manual.html` en el PDF A4 con portada, índice con páginas y pie |
| `docs/manual/generar/revisar.py` | Revisa el HTML, el PDF y este archivo: falla si aparece la palabra prohibida o un precio del software |
| `docs/manual/generar/imagenes/` | Fotos de ejemplo para la ficha técnica y el comprobante del anticipo |
