#!/usr/bin/env python3
"""Revisa que el manual de TuSalón no rompa las reglas de la casa:
  - nunca la palabra «gratis» (ni «gratuito», ni «prueba gratis»): solo se habla de demos;
  - ningún precio del software: ni los precios de los planes (db/schema.sql) ni de los módulos (src/Modulos.php), ni un monto
    junto a palabras del software (plan, módulo, suscripción, Google Play, TuSalón, Tukán…).
Los precios de los servicios del salón sí pueden aparecer (son del cliente).
Uso: python3 docs/manual/generar/revisar.py docs/manual/manual.html docs/Manual_TuSalon.pdf [docs/MANUAL.md]
Sale con código 1 y explica qué encontró si algo no cumple."""
import html, re, subprocess, sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[3]
PROHIBIDA = re.compile(r'(?i)\bgrat(is|uit[oa]s?)\b|prueba\s+gratis|d[ií]as\s+de\s+prueba')
MONTO = re.compile(r'\$\s?(\d+(?:[.,]\d{1,2})?)')
SOFTWARE = re.compile(r'(?i)\b(plan(es)?|m[oó]dulos?|suscripci[oó]n|google\s*play|tusal[oó]n|tuk[aá]n|software|mensualidad|renovar|contratar)\b')


def precios_software():
    """Precios de los planes y de los módulos tal como están en el código (para buscarlos en el texto)."""
    p = set()
    esquema = (RAIZ / 'db/schema.sql').read_text(encoding='utf-8')
    for m in re.finditer(r"\('(\w+)',\s*'[^']+',\s*(\d+(?:\.\d+)?)", esquema.split('INSERT INTO planes', 1)[1].split(';', 1)[0]):
        p.add(float(m.group(2)))
    return {x for x in p if x > 0}


def texto_html(ruta):
    t = Path(ruta).read_text(encoding='utf-8')
    t = re.sub(r'(?s)<(style|script)\b.*?</\1>', ' ', t)
    t = re.sub(r'<br\s*/?>|</(p|li|tr|h\d|div|td|th|figcaption)>', '\n', t)
    return html.unescape(re.sub(r'<[^>]+>', ' ', t))


def texto_pdf(ruta):
    return subprocess.run(['pdftotext', '-layout', str(ruta), '-'], capture_output=True, text=True, check=True).stdout


def revisar(nombre, texto, planes):
    malas = []
    for n, linea in enumerate(texto.splitlines(), 1):
        l = re.sub(r'\s+', ' ', linea).strip()
        if not l:
            continue
        if PROHIBIDA.search(l):
            malas.append(f'{nombre}, línea {n}: palabra prohibida → «{l[:140]}»')
        montos = [float(m.group(1).replace(',', '.')) for m in MONTO.finditer(l)]
        if montos and SOFTWARE.search(l):
            malas.append(f'{nombre}, línea {n}: monto junto a una palabra del software → «{l[:140]}»')
        elif any(x in planes for x in montos):
            malas.append(f'{nombre}, línea {n}: monto igual al precio de un plan → «{l[:140]}»')
    return malas


def main():
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    planes = precios_software()
    malas = []
    for ruta in sys.argv[1:]:
        r = Path(ruta)
        if not r.exists():
            malas.append(f'No existe {ruta}')
            continue
        texto = texto_pdf(r) if r.suffix == '.pdf' else texto_html(r) if r.suffix == '.html' else r.read_text(encoding='utf-8')
        malas += revisar(r.name, texto, planes)
    if malas:
        print('REVISIÓN FALLIDA: el manual no cumple las reglas (nada «gratis», ningún precio del software):')
        print('\n'.join('  - ' + m for m in malas))
        sys.exit(1)
    print(f'Revisión OK: sin la palabra prohibida ni precios del software ({", ".join(Path(x).name for x in sys.argv[1:])}).')


main()
