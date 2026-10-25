---
name: tukan-sistema-nuevo
description: Usar cuando Dimitry pide crear un programa Tukán NUEVO (TuAlgo) copiando uno que ya existe, por ejemplo TuSalón → TuGuardería, TuEvento → TuPlanner o un sistema para un cliente nuevo como TuRemolque. NO usar para agregar una función a un programa que ya existe (eso es tukan-modulo-nuevo) ni para corregir errores (arreglar-error-sin-romper).
---

# Crear un programa Tukán nuevo a partir de otro

## Reglas de siempre (no se negocian)
- Responder en español, explicado como si Dimitry no supiera nada de programación.
- Todo para copiar y pegar: comandos completos, cero "y luego ajusta…".
- Código COMPLETO. Prohibido escribir "aquí va el resto", "igual que antes" o "…".
- Se COPIA el molde, no se reescribe desde cero. Mismo lenguaje (PHP 8.3+ y PostgreSQL), mismas carpetas, mismo diseño.
- Textos dentro del programa con "usted". Nunca la palabra de regalar (se dice "demo" o "meses de regalo") y nunca precios del software (siempre "Pedir cotización").
- Claude NO crea repositorios, NO publica nada y NO instala en el servidor sin un "sí" de Dimitry.

## Pasos
1. **Confirmar 4 datos** (preguntar solo lo que falte, en una sola pregunta):
   nombre (TuAlgo), programa molde (si no dice: TuSalón), a quién se vende, y qué cosas son distintas del molde.
2. **Leer el molde** antes de tocar nada: `README.md`, `BLOC_DE_NOTAS.md`, `docs/GUIA_DESARROLLO.md`, `src/Modulos.php`, `app/vistas/_diseno.php`.
3. **Copiar el repositorio entero** del molde a la carpeta nueva (sin `.git`), conservando `app/ bin/ db/ deploy/ docs/ public/ src/ tests/`.
4. **Buscar el nombre viejo Y las palabras del negocio viejo** con el script de esta carpeta:
   `bash buscar_nombres.sh <carpeta-nueva> tusalon "peluquer|barber|sal[oó]n"`. Lista cada lugar a cambiar.
   Si dice "ERROR: no existe la carpeta", la revisión NO se hizo.
5. **Cambiar todos los nombres**: espacio de nombres PHP (`TuSalon\` → `TuAlgo\`), variables del `.env` (`TUSALON_` → `TUALGO_`), base de datos, carpeta `/var/www/<nuevo>`, dominio `<nuevo>.tukanec.com`, manifiesto y título.
   **Textos que ve el cliente** con palabras del negocio viejo ("peluquero", "salón") → palabras del negocio nuevo ("cuidador", "guardería").
   **Nombres internos** (tablas y columnas como `salones`, `salon_id`) se DEJAN igual salvo que Dimitry pida otra cosa: cambiarlos rompe el conector del Panel Tukán Central y las pruebas.
6. **Dejar IGUAL, sin tocar**: diseño (`_diseno.php`, `public/assets`), conector del Panel Tukán Central, librerías `src/tukan_ia.php` y `src/tukan_correo.php`, sistema de módulos, "todo se pide demo", papelera.
7. **Lo que no sirve para el negocio nuevo** (ej. ficha de color en una guardería): hacer una lista y pedir OK antes de quitarlo. Quitar = sacarlo del catálogo de módulos y de las rutas, no dejar código roto.
8. **Base de datos**: `db/schema.sql` con el nombre nuevo; las bases de prueba llevan "prueba" en el nombre.
9. **Probar**: `bash tests/run_logica.sh` y `PGPASSWORD=prueba bash tests/run_web.sh` hasta que digan `TODO BIEN`. Correr `buscar_nombres.sh` otra vez: la marca vieja debe dar 0 y las palabras del negocio viejo solo deben quedar en nombres internos. Regenerar el manual en PDF con el nombre nuevo.
10. **Documentar**: README nuevo (qué es, para quién, direcciones) y `BLOC_DE_NOTAS.md` (decisiones y pendientes).
11. **GitHub**: Dimitry crea el repositorio privado `qfcorp/<nombre>`; Claude sube el código, abre el PR y hace merge a `main`.
12. **Entregar** en este orden: dirección donde quedará, qué se copió, qué se quitó, qué falta, y el comando del instalador listo para pegar (solo se corre cuando Dimitry diga).

## Ejemplo bueno 1
Pedido: "Hazme TuGuardería a partir de TuSalón."
Claude pregunta solo lo que falta ("¿Qué hace distinto una guardería: estadía por días, mascotas en vez de clientes?"), copia TuSalón, cambia `TuSalon\` por `TuGuarderia\`, propone quitar "Ficha técnica de color" y espera OK, corre las pruebas (`TODO BIEN`), y entrega el resumen + comando del instalador.

## Ejemplo bueno 2
Pedido: "TuPlanner sale de TuEvento, repo aparte."
Claude usa TuEvento como molde (no TuSalón, porque Dimitry lo dijo), deja el diseño idéntico, agrega en el BLOC las 4 formas de cobro del organizador como pendientes y no inventa precios.

## Ejemplo malo (no hacer)
"Creé TuHospedaje desde cero con Laravel y Bootstrap, con un plan gratis de 7 días y precios en la página."
Por qué es malo: no copió el molde (otro lenguaje, otro diseño), usa la palabra prohibida, publica precios y deja a Dimitry con un sistema que no se parece a los demás ni se conecta al Panel Tukán Central.
