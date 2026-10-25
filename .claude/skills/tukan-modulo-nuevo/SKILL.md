---
name: tukan-modulo-nuevo
description: Usar cuando Dimitry pide agregar una función, pantalla o módulo nuevo (pagos, reportes, inventario, citas, avisos, etc.) a un programa Tukán que YA existe (TuSalón, TuGuardería, TuHospedaje, TuPlanner, TuRemolque, TuAcademia…). NO usar para crear un programa entero (tukan-sistema-nuevo) ni para corregir un error (arreglar-error-sin-romper).
---

# Agregar un módulo a un programa Tukán

## Reglas de siempre (no se negocian)
- Español, explicado como si Dimitry no supiera nada. Todo para copiar y pegar.
- Código COMPLETO de cada archivo que se cambia. Prohibido "aquí va el resto" o "…".
- Tocar SOLO lo que el módulo necesita. Si se ve otro problema, se anota y se pregunta; no se arregla "de paso".
- Misma estructura y diseño que el resto del programa. Textos con "usted", sin precios del software, sin la palabra de regalar.
- Apagar un módulo NUNCA borra datos.

## Pasos
1. **Confirmar** (una sola pregunta si falta algo): qué hace, quién lo usa (dueño, administrador, recepción, peluquero, cliente), si depende de otro módulo, y si va en Básica, Completa o como extra.
2. **Leer primero**: `docs/GUIA_DESARROLLO.md` (sección Módulos), `src/Modulos.php`, `BLOC_DE_NOTAS.md`. Si el programa NO tiene `src/Modulos.php`, parar y preguntar.
3. **Base de datos**: migración nueva con el siguiente número (`db/migraciones/0NN_nombre.sql`) que se pueda correr muchas veces (`IF NOT EXISTS`), y el mismo cambio al final de `db/schema.sql`, antes del `COMMIT;`. Columnas `NOT NULL` siempre con `DEFAULT`.
4. **Registrar el módulo** en `Modulos::CATALOGO` (ícono, nombre, grupo, descripción para vender, dependencias). Precio solo interno: el cliente ve "Pedir cotización".
5. **Pantallas**: cada ruta nueva en `Modulos::RUTAS` y en `public/index.php`. Botones o secciones dentro de otras pantallas con `<?php if (modulo('id')): ?>`; para el dueño, `aviso_modulo('id')`. Si la página es pública, agregarla a `Papelera::RUTAS_PUBLICAS` o `PUBLICAS_SIN_SALON`.
6. **Lógica** en una clase nueva de `src/` que pregunte a `Modulos` con el id del salón. Toda consulta que liste o cuente salones lleva `WHERE eliminado_en IS NULL` (así no salen los que están en la papelera).
7. **Pruebas**: `tests/prueba_<modulo>.php` y `tests/prueba_web_<modulo>.sh`. Correr `bash tests/run_logica.sh` y `PGPASSWORD=prueba bash tests/run_web.sh` hasta `TODO BIEN`, sin avisos de PHP.
8. **Documentar**: fila en la tabla de módulos del README, sección en `docs/GUIA_DESARROLLO.md`, avance en `BLOC_DE_NOTAS.md`, y el manual (`docs/manual/`) si el cliente lo va a usar.
9. **GitHub**: Claude hace rama nueva, commit y PR, y pregunta "¿Uno el cambio a main?". Con el "sí" de Dimitry hace el merge.
10. **Entregar**: qué hace el módulo en 2 frases, lista de archivos cambiados, el comando para actualizar el servidor (el instalador corre la migración) y "cómo probarlo": dónde hacer clic y qué debe verse.

## Ejemplo bueno 1
Pedido: "Agrega a TuSalón avisos por WhatsApp oficial."
Claude pregunta si es extra o va en Completa, crea `011_whatsapp_oficial.sql`, el módulo `whatsapp_oficial` en el catálogo, la clase en `src/`, esconde los botones con `modulo('whatsapp_oficial')`, escribe sus 2 pruebas, todo `TODO BIEN`, PR + merge, y entrega el comando para actualizar.

## Ejemplo bueno 2
Pedido: "En TuGuardería quiero reporte de estadías por mes."
Claude revisa que existe un módulo «Reporte del mes» y propone agregar la sección ahí en vez de crear un módulo repetido; espera OK y sigue los pasos.

## Ejemplo malo (no hacer)
"Agregué la tabla con un ALTER TABLE en el servidor y puse el botón en la vista; el resto del archivo queda igual."
Por qué es malo: no hay migración ni cambio en `schema.sql` (el próximo servidor queda sin la tabla), el botón se ve aunque el salón no tenga el módulo, no hay pruebas, y "el resto queda igual" obliga a Dimitry a armar el archivo a mano.
