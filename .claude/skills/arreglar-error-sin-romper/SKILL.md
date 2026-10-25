---
name: arreglar-error-sin-romper
description: Usar cuando algo falla DENTRO de un programa de Dimitry (Tukán, ERP QF, ligas/tuvocalia, arriendos): sale un error en una pantalla, pantalla en blanco, no guarda, un total sale mal, un botón no hace nada. Encuentra la causa y la corrige con el cambio más pequeño posible. NO usar si lo que falla es el servidor, el correo, el dominio o la página entera no abre (eso es servidor-tarea-segura), ni para funciones nuevas (tukan-modulo-nuevo).
---

# Corregir un error sin romper lo que funciona

## Reglas de siempre (no se negocian)
- Español, explicado como si Dimitry no supiera nada. Todo para copiar y pegar.
- Cambio MÍNIMO: solo las líneas que causan el error. Nada de "de paso mejoré…".
- Si se ve otro problema, se anota al final como "Encontré esto también, ¿lo arreglo?" y NO se toca.
- Código completo de lo que se cambia. Nunca "…" ni "el resto igual".
- Si no se sabe algo (qué pantalla, qué dato), preguntar. No inventar.

## Pasos
1. **Ubicar el error**: dirección exacta (URL), qué hizo Dimitry y qué esperaba ver. Si falta, pedir UNA cosa: la dirección o una captura.
2. **Encontrar el archivo REAL** que abre esa dirección antes de editar:
   - ERP QF: hay pantallas con dos archivos parecidos (ej. `eventos_editar.php` y `eventos_editar_contenido.php`). Darle a Dimitry estos comandos, uno por bloque, cambiando solo el nombre de la pantalla de la dirección:
     `ls -la /var/www/erp_qf/ | grep -i "eventos_editar"` (qué archivos parecidos hay)
     `grep -n "include\|require" /var/www/erp_qf/eventos_editar.php` (cuál carga de verdad)
   - Tukán: `public/index.php` → ruta `?r=` → `app/paginas/<ruta>.php` y `app/vistas/<ruta>.php`. Claude lo revisa solo en el repositorio, sin pedirle nada a Dimitry.
3. **Buscar la causa de raíz**, no tapar el síntoma. Revisar primero los errores que ya pasaron antes:
   columna `NOT NULL` sin valor por defecto · guarda en la tabla equivocada · `$pdo` sin crear · un filtro de fechas que cambia el cálculo (debe cambiar solo lo que se MUESTRA) · datos guardados en `$_SESSION` que se mezclan entre dos personas.
4. **Explicar la causa** en 1 o 2 frases simples antes de dar el arreglo.
5. **Arreglar** con el cambio mínimo.
6. **Comprobar**: Tukán → agregar el caso a las pruebas y correr `bash tests/run_logica.sh` y `run_web.sh` hasta `TODO BIEN`. ERP → `php -l` del archivo y, si se puede, probarlo aquí antes de mandarlo.
7. **Aplicar**:
   - Tukán: Claude hace commit, PR y merge a `main`, y da el comando para actualizar el servidor.
   - ERP QF: seguir la habilidad `erp-qf-cambio-produccion` para entregar los comandos.
8. **Entregar**: causa (simple), qué se cambió, cómo comprobarlo (dónde hacer clic y qué debe verse) y cómo deshacerlo.

## Ejemplo bueno 1
"En el ERP, el panel PPV se mezcla si dos personas lo usan."
Claude encuentra que `ppv_evento_control.php` lee la base desde `$_SESSION['ppv_db_actual']`, explica: "las dos personas comparten un mismo dato guardado; cuando una cambia de evento, le cambia a la otra", y cambia solo los enlaces para que lleven `db=` en cada botón.

## Ejemplo bueno 2
"En TuSalón la caja no cuadra con propinas."
Claude reproduce el caso en una prueba que falla, corrige una línea en `src/Caja.php`, la prueba pasa, PR + merge, y avisa: "Además vi que el reporte del mes redondea distinto, ¿lo reviso?".

## Ejemplo malo (no hacer)
"Reescribí `eventos_editar.php` completo con un diseño más moderno y arreglé el error."
Por qué es malo: era el archivo que el navegador NO usaba (el real era `_contenido.php`), así que el error sigue; y además cambió el diseño sin que nadie lo pidiera.
