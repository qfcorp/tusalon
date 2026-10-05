# Bloc de notas — TuSalón (software de peluquerías y barberías, marca Tukán)

Última actualización: 4 de octubre de 2026, 23:20

## Qué es
Sistema para peluquerías y barberías de Ecuador. Un mismo local puede tener 4 tipos de peluquero a la vez:
- **Dueño que atiende:** sin comisión ni licencia extra; ve por separado lo que produjo y lo que ganó el local
- **Empleado:** sueldo, comisión o ambos; rol de pagos con IESS y décimos
- **Porcentaje:** 50/50, 60/40, con reparto automático
- **Alquiler de puesto:** cobra con su propio QR; el sistema le cobra el arriendo

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
| Agenda | Hasta 3 personas | Ilimitado, varias sucursales |
| 4 tipos de peluquero | Cálculo simple | Completo |
| Ficha del cliente | Básica | Ficha técnica de color con fotos |
| Caja diaria | Sí | Sí |
| WhatsApp | Con un clic | Automático (API oficial) |
| Reservas en línea, anticipos (Plux/Payphone) | No | Sí |
| Comisiones avanzadas, anticipos, rol de pagos IESS | No | Sí |
| Cobro automático de arriendo, reparto del porcentaje | No | Sí |
| Clientes privados del arrendatario, vista doble del dueño | No | Sí |
| App del peluquero, inventario, fidelidad | No | Sí |
| Importar facturas recibidas (gastos en el panel) | No | Sí |
| Reportes | Del día | Rentabilidad por servicio y peluquero |

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
3b. [x] **TuSalón en línea: https://tusalon.qfradioec.com** (4 oct, 23:20). Instalación en qfcorp (4 oct, 23:08): **hecha** con el instalador de una línea (`deploy/instalar.sh`): base PostgreSQL 18 con 29 tablas, nginx, PHP 8.5, tareas cada hora y respaldo diario 3:15 funcionando (prueba local 200)
    - [x] Cloudflare: ruta `tusalon.qfradioec.com` → `http://localhost:80` agregada en el túnel **servidorqf** (Redes → Conectores → servidorqf → Rutas de aplicaciones publicadas). El config.yml del servidor es solo una plantilla; el túnel se maneja desde la web
    - [ ] Falta: crear el bot de Telegram (docs/TELEGRAM.md)
    - [ ] Manual para clientes: empezado (documento "Manual de TuSalón", con índice), falta llenarlo con capturas
    - [ ] Decidir si el repositorio de GitHub pasa a privado (hoy es público)
    - Para actualizar el servidor: la misma línea del instalador (no borra datos)
3c. [ ] Más adelante: aviso automático por WhatsApp (API oficial de Meta); hoy los avisos son la campana y Telegram
4. [ ] Completa 1: reservas en línea, WhatsApp automático, anticipos con Plux o Payphone
5. [ ] Completa 2: comisiones avanzadas, rol de pagos, cobro de arriendo, reparto del porcentaje
6. [ ] Completa 3: ficha técnica, inventario, fidelidad, importar facturas recibidas del SRI, app del peluquero
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
- [ ] Anticipo en línea para clientes nuevos o que faltaron (Payphone/Plux): queda para el paso 4

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
