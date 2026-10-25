# Cambios de tukan-sistema-nuevo

## 2026-10-25 · versión 1
- Creada a partir de la entrevista con Dimitry y de la revisión de sus proyectos.

## 2026-10-25 · versión 2 (corregida en la prueba)
- `buscar_nombres.sh` decía "0 archivos" cuando la carpeta no existía (parecía que todo estaba bien). Ahora avisa ERROR y se detiene.
- El script solo buscaba la marca (TuSalón) y no las palabras del negocio viejo ("peluquero", "salón"), que estaban en 189 archivos. Ahora acepta esas palabras como tercer dato.
- Ya no corta la lista en 500 líneas.
- SKILL.md: regla nueva → textos que ve el cliente se cambian; nombres internos de tablas (`salon_id`) se dejan para no romper el conector.

## 2026-10-25 · versión final
- El merge a `main` ahora se hace solo después del "sí" de Dimitry en el chat (regla de seguridad: nada se publica sin aprobación).
