# Cómo reparte el dinero TuSalón

Cada peluquero tiene una **cuenta corriente** con el local:

- Número **positivo**: el local le debe al peluquero.
- Número **negativo**: el peluquero le debe al local.

Cada vez que se cobra algo, el sistema anota automáticamente lo que le toca a cada uno según su tipo.

| Tipo | Si cobra el local | Si cobra el peluquero | Productos del local que vende |
|---|---|---|---|
| **Dueño** | Nada (es suyo); cuenta como su producción | — | — |
| **Empleado** | + su comisión de servicio | — | + su comisión de producto |
| **Porcentaje** | + su % (ej. 60 % de $12 = +$7,20) | − la parte del local (ej. 50 % de $12 = −$6) | + comisión, o − (precio − comisión) si cobró él |
| **Alquiler** | + todo el valor (el local cobró por él) | Nada (el dinero ya es suyo) | Igual que porcentaje |

**Pago fijo por servicio:** el dueño puede definir en cada servicio cuánto le paga al peluquero (ej. Corte premium: cliente paga $15, al peluquero $6). Para los **empleados** se usa ese pago fijo en lugar de su %; si el dueño cobra otro precio al cliente, el peluquero gana igual su pago fijo. Quien trabaja por porcentaje sigue con su % y quien alquila se queda con todo.

Además:
- **Propina:** va al peluquero del primer servicio. Si la cobró el local, se le suma; si la cobró él, ya la tiene.
- **Arriendo:** se le resta al arrendatario cada semana, quincena o mes.
- **Anticipos y préstamos:** se restan.
- **Escalas (solo plan Completa):** si el empleado pasa una meta (ej. $1.000 en servicios), se le paga la diferencia del % más alto.

## Liquidación
Al cerrar el periodo, el sistema suma la cuenta y dice en palabras simples:
- "El local le paga $7,20", o
- "El profesional le paga al local $65,50".

## Rol de pagos (empleados, plan Completa)
- Sueldo + comisiones. Si no llega al básico ($482 en 2026), se completa.
- Las comisiones **sí** suman para el IESS (9,45 % personal, 11,15 % patronal) y el décimo tercero.
- Las propinas se entregan aparte y no aportan al IESS.
- Fondos de reserva (8,33 %) desde el mes 13.

## Vista doble del dueño
- **Producción en la silla:** lo que el dueño cortó.
- **Ganancia del local:** lo que entró a la caja, menos lo que se les debe a los peluqueros, más lo que ellos le deben al local (arriendos, partes del local).
- **Gastos:** las facturas que el salón **recibe** de sus proveedores (tintes, shampoo, luz, agua, arriendo del local), importadas del SRI y separadas por categoría. Cada proveedor recuerda su categoría.
- **Ganancia después de gastos:** ganancia del local menos esos gastos. Los sueldos fijos e IESS se ven en el rol de pagos.

## Ejemplo probado (Barbería Don Pepe, un día)
| Peluquero | Tipo | Qué pasó | Cuenta |
|---|---|---|---|
| Pepe | Dueño | Corte $10 | $0 (produjo $10) |
| Ana | Empleada 20 % / 10 % | Corte $10 + tinte $40 + shampoo $15 + propina $2 | +$13,50 |
| Luis | Porcentaje 60 %, cobra el local | Corte $12 | +$7,20 |
| Marta | Porcentaje 50 %, cobra ella | Corte $12 | −$6,00 |
| Carlos | Alquiler $60/semana | Corte $8 con su QR, shampoo cobrado por él, otro corte $8 pagado en el local | −$65,50 |

Ganancia del local ese día: **$147,80**. Ese día llegaron facturas de tintes ($20) y de luz ($15): ganancia después de gastos **$112,80**.
