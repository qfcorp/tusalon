# Cambios de tukan-modulo-nuevo

## 2026-10-25 · versión 1
- Creada a partir de la entrevista con Dimitry y de la revisión de sus proyectos.

## 2026-10-25 · versión 2 (corregida en la prueba)
- Se comprobó contra el código real de TuSalón que existen todos los nombres que usa la habilidad (`Modulos::CATALOGO`, `Modulos::RUTAS`, `modulo()`, `aviso_modulo()`, `Papelera::RUTAS_PUBLICAS`, `PUBLICAS_SIN_SALON`, `COMMIT;` en `schema.sql`).
- La regla de la papelera estaba escrita al revés de cómo se lee ("excluye eliminado_en IS NOT NULL"). Ahora dice exactamente lo que se escribe: `WHERE eliminado_en IS NULL`.

## 2026-10-25 · versión final
- El merge a `main` ahora se hace solo después del "sí" de Dimitry en el chat (regla de seguridad: nada se publica sin aprobación).
