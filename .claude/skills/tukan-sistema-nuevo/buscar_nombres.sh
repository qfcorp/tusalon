#!/usr/bin/env bash
# Lista cada lugar donde todavía aparece el nombre del programa molde
# y, si se le pasan, las palabras del negocio viejo (ej. peluquero, salón).
# Solo LEE, no cambia nada.
# Uso:    bash buscar_nombres.sh <carpeta-nueva> <nombre-viejo> ["palabra1|palabra2|..."]
# Ejemplo: bash buscar_nombres.sh ~/tuguarderia tusalon "peluquer|barber|sal[oó]n"
set -u
CARPETA="${1:?Falta la carpeta del programa nuevo}"
VIEJO="${2:?Falta el nombre viejo, por ejemplo tusalon}"
PALABRAS="${3:-}"
if [ ! -d "$CARPETA" ]; then
  echo "ERROR: no existe la carpeta $CARPETA. No se revisó nada (esto NO es un resultado de 0)."
  exit 1
fi

# Variantes de la marca: tusalon, TuSalon, TuSalón, TUSALON, tu salón
BASE="${VIEJO#tu}"; BASE="${BASE#Tu}"
CON_TILDES="$(echo "$BASE" | sed 's/a/[aá]/g; s/e/[eé]/g; s/i/[ií]/g; s/o/[oó]/g; s/u/[uú]/g')"
MARCA="tu[ _-]?${CON_TILDES}"
EXCLUIR=(--exclude-dir=.git --exclude='*.pdf' --exclude='*.png' --exclude='*.jpg' --exclude='*.jpeg' --exclude='*.webp' --exclude='*.ico')

echo "== 1. Archivos y carpetas con la marca vieja en su nombre"
find "$CARPETA" -path '*/.git' -prune -o -iname "*${BASE}*" -print

echo "== 2. Líneas con la marca vieja (archivo:línea)"
grep -rniE "$MARCA" "$CARPETA" "${EXCLUIR[@]}"
MARCA_TOTAL=$(grep -rliE "$MARCA" "$CARPETA" "${EXCLUIR[@]}" | wc -l)

PAL_TOTAL=0
if [ -n "$PALABRAS" ]; then
  echo "== 3. Líneas con palabras del negocio viejo: $PALABRAS"
  grep -rniE "$PALABRAS" "$CARPETA" "${EXCLUIR[@]}"
  PAL_TOTAL=$(grep -rliE "$PALABRAS" "$CARPETA" "${EXCLUIR[@]}" | wc -l)
fi

echo "== RESUMEN"
echo "   Archivos con la marca vieja: $MARCA_TOTAL (debe llegar a 0)"
[ -n "$PALABRAS" ] && echo "   Archivos con palabras del negocio viejo: $PAL_TOTAL (revisar uno por uno: los textos que ve el cliente se cambian; los nombres internos de tablas como salon_id se dejan si Dimitry no dice otra cosa)"
echo "   Los PDF del manual no se revisan aquí: se vuelven a generar al final."
