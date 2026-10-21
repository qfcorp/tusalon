-- 010 · El módulo «Asistente IA» (asistente_ia) viene INCLUIDO en el plan Completa (decisión de Dimitry, 21 oct 2026).
--   En Básica sigue como extra por cotización (salones.modulos_extra). Completa normalmente es '*' (todos los módulos, y
--   desde ahora '*' también trae el Asistente IA: Modulos::FUERA_DE_PLANES quedó vacío); si el súper usuario guardó
--   Completa con una lista, aquí se le agrega asistente_ia.
--   Tope de IA con el módulo (en el plan o comprado aparte): $4,00 al mes por salón (Ia::TOPE_CON_MODULO);
--   sin el módulo la Ayuda sigue con $0,50. salones.ia_tope_usd vacío = el sugerido.
-- Se puede correr muchas veces sin dañar nada.

UPDATE planes
   SET modulos = CASE WHEN btrim(modulos) = '' THEN 'asistente_ia' ELSE modulos || ',asistente_ia' END
 WHERE codigo = 'completa'
   AND modulos <> '*'
   AND (',' || replace(modulos, ' ', '') || ',') NOT LIKE '%,asistente_ia,%';
