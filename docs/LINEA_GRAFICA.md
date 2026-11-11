# Línea gráfica de TuSalón (identidad común Tukán, 10 nov 2026)

Mismo diseño y misma lógica que todos los programas Tukán (modelo: el ERP para productoras). Kit común
`public/assets/tukan-ui.css` y `public/assets/tukan-ui.js`: **idénticos byte a byte** en todos los programas (no se editan aquí;
las reglas propias de TuSalón van al final de `public/assets/app.css`, sección «Identidad gráfica común de Tukán»).

## Color del programa
`<style>:root{ --tk-color:#1E5A4C; --tk-color-claro:#7FD1B9; --tk-color-texto:#ffffff; }</style>` (en `app/vistas/_diseno.php`)
- `#1E5A4C` verde botella de siempre (barra de arriba, títulos de sección del menú, opción activa, botón principal). Texto blanco (contraste 8:1).
- `#7FD1B9` versión clara para números y títulos sobre el azul noche.

## Estructura (todas las pantallas internas)
- `body class="tk"` · barra `.tk-arriba` (iniciales **Ts** en el círculo, nombre del salón, «TuSalón · Plan …» o «Demo», usuario, rol y **Salir**).
- Menú `.tk-menu` azul noche con secciones y emoji; en el celular se abre con **☰** (ya no hay barra de abajo ni pantalla «Más»).
- Avisos de arriba como `.tk-tira` (`app/vistas/_tiras.php`): demo, plan por vencer, administrador de Tukán viendo, términos por aceptar.
- Las pantallas usan las clases del kit (`.tk-titulo-pagina`, `.tk-tarjeta`, `.tk-boton`, `.tk-tabla`, `.tk-campo`, `.tk-aviso`, `.tk-chip`) junto a las de siempre.
- NO usan el kit: el portal público de reservas y la cuenta del cliente (`_publico.php`), el Panel Tukán (`_admin.php`), los PDF y los correos.

## Grupos del menú
| Quién | Secciones y opciones |
|---|---|
| Dueño y administradores | **Principal**: 📊 Inicio, 📅 Agenda, 🔔 Solicitudes (globito con las sin leer), 💵 Caja · **Clientes y ventas**: 👥 Clientes, 📣 Avisos a clientes, 📦 Inventario*, 🎁 Paquetes*, 🎟️ Tarjetas de regalo*, ⭐ Puntos* · **Equipo**: 💇 Equipo, 🧮 Cuentas del equipo, 🛎️ Recepción*, 🛡️ Administradores · **Dinero**: 📈 Reporte del mes*, 🧾 Gastos y facturas*, 🧾 Factura electrónica · **Configuración**: ✂️ Servicios y precios, 🕘 Horario y reservas en línea, 💳 Mi plan, ✨ Asistente IA*, ❓ Ayuda |
| Recepción | **Principal**: Inicio, Agenda, Solicitudes, Caja · **Clientes**: Clientes, Avisos a clientes · **Ayuda**: Asistente IA*, Ayuda |
| Peluquero | **Mi trabajo**: Mi día, Solicitudes, Mis clientes (quien alquila, con su módulo) · **Ayuda** |
\* solo si el salón tiene ese módulo. Al final: 🚪 Cerrar sesión y los enlaces Términos · Privacidad.

## Inicio (dashboard), en este orden
1. Saludo según la hora de Ecuador (☀️ Buenos días 5–12 h, 🌤️ Buenas tardes 12–19 h, 🌙 Buenas noches) + fecha.
2. Avisos `.tk-tira` (demo con días y «Contratar mi plan», plan por vencer, términos) y «Tienes N solicitudes de cita por aceptar · Ver ahora»; tarjeta de la demo con el manual y los primeros pasos en PDF.
3. **Primeros pasos** (dueño y administradores; se calculan con los datos): servicios y precios · equipo (más de una persona) · primer cliente · primera cita · primer cobro · Mi plan y términos aceptados. Botón «Hacerlo», barra de avance, desaparece al completarlos; «Ocultar» se guarda por salón en `ajustes` (`pasos_ocultos_<id>`).
4. **Cifras**: Entró a la caja hoy · Citas por atender (y atendidas) · Solicitudes por aceptar · Ganancia del local hoy (no para recepción).
5. **Tarjetas**: 📅 Próximas citas (con WhatsApp) · 🔔 Pendientes de hoy (comprobantes de anticipo, productos que se acaban, citas de mañana por recordar, cumpleaños) · 💼 Tu día como dueño (no para recepción).
6. **Accesos rápidos**: Nueva cita, Cobrar, Agenda, Clientes, Caja del día y Asistente IA (con su módulo).
