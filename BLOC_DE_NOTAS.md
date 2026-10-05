# Bloc de notas — TuSalón (software de peluquerías y barberías, marca Tukán)

Última actualización: 4 de octubre de 2026, 20:45

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
- [x] SRI: NO emitimos facturas. Solo se importan las facturas que el local ya emite (como el sincronizador del ERP)
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
| Importar facturas del SRI | No | Sí |
| Reportes | Del día | Rentabilidad por servicio y peluquero |

## Decisiones pendientes
- [ ] Peluquería conocida para la primera prueba gratis

## Hecho
- [x] Investigación de mercado (21 programas mundiales, 15 de Latinoamérica, contexto Ecuador)
- [x] Revisión de precios de Beauty360Pro (competidor ecuatoriano): $14 / $29 / $49 al mes, solo mensual, sin descuento anual, 15 días de prueba, cobra con Payphone
- [x] Definición de planes y precios
- [x] Base de datos completa (`db/schema.sql`): salones, planes, suscripciones, 4 tipos de peluquero, reglas de pago, escalas, clientes privados, ficha técnica, citas, ventas, cuenta corriente, liquidaciones, caja, facturas importadas
- [x] Lógica del dinero (`src/`): reparto por tipo, propinas, arriendo, anticipos, escalas, rol de pagos con IESS, liquidación, vista doble del dueño
- [x] Prueba simulada "Barbería Don Pepe": **49 de 49 pruebas correctas**. Se metió un error a propósito y las pruebas lo detectaron
- [x] Explicación simple del reparto: `docs/COMO_FUNCIONA_EL_DINERO.md`

## Plan de trabajo
1. [x] Repositorio qfcorp/tusalon en GitHub con este bloc de notas
2. [x] Base de datos con los 4 tipos de peluquero + prueba en entorno simulado
3. [ ] **SIGUIENTE:** Plan Básica: pantallas de login, agenda, clientes, caja diaria, cálculo simple de los 4 tipos, WhatsApp con un clic; primera instalación en tusalon.qfradioec.com
4. [ ] Completa 1: reservas en línea, WhatsApp automático, anticipos con Plux o Payphone
5. [ ] Completa 2: comisiones avanzadas, rol de pagos, cobro de arriendo, reparto del porcentaje
6. [ ] Completa 3: ficha técnica, inventario, fidelidad, importar facturas SRI, app del peluquero
7. [ ] Cobro de la suscripción (mensual, semestral, anual) y bloqueo de funciones según el plan
8. [ ] Prueba real en una peluquería, ajustes y lanzamiento en tukansoftware.com

## Datos clave
- 21.144 peluquerías/salones con RUC en Ecuador (INEC 2023); 67 % sin empleados afiliados
- Payphone es la única pasarela local que reparte un cobro entre varias cuentas (Split de Pagos)
- Recordatorio por WhatsApp oficial: ~$0,0113 cada uno
- La Completa de $35 queda entre el Profesional ($29) y el Premium ($49) de Beauty360Pro, con más cosas que su Premium (alquiler de silla, porcentaje, WhatsApp automático)
- La Básica de $25 sirve para que la Completa se vea barata (solo $10 más)
