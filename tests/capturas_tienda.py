#!/usr/bin/env python3
"""Pantallas crudas del celular (1080 x 2160) para la ficha de Google Play de TuSalón.
La llama tests/capturas_tienda.sh con un TuSalón LOCAL y el salón de ejemplo del manual (docs/manual/generar/demo.php, datos inventados).
Entra como en la app de Google Play (?app=play: sin precios del software). Se niega a usar otra dirección que no sea local.
Uso: python3 tests/capturas_tienda.py URL datos.env CARPETA"""
import re, sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

url, datos, carpeta = sys.argv[1:4]
url = url.rstrip('/')
if urlparse(url).hostname not in ('127.0.0.1', 'localhost'):
    sys.exit('Solo se sacan capturas de un TuSalón local (127.0.0.1), nunca del servidor real.')
D = dict(l.strip().split('=', 1) for l in Path(datos).read_text().splitlines() if '=' in l)
CLAVE = 'SalonDemo2026'
CUENTAS = {'dueno': 'demo@tusalon.tukanec.com', 'carlos': 'carlos@tusalon.tukanec.com'}
# Precio del software (no los precios del negocio, como sus servicios o membresías) o pago por fuera de Google
PRECIO_SOFTWARE = re.compile(r'(?i)(plan (básic|complet)[^\n]{0,40}\$\s?\d|total al mes|lo que pagas|gratis|gratuit|wa\.me/593996408397\?text|pedir cotización)')

# (archivo, quién, ruta, acción, bajar)
PANTALLAS = [
    ('01_inicio', 'dueno', '/?r=inicio', None, 0),
    ('02_agenda', 'dueno', '/?r=agenda&fecha={MANANA}', None, 0),
    ('03_cita_nueva', 'dueno', '/?r=cita_nueva&fecha={MANANA}&hora=13:00', None, 0),
    ('04_reservar', 'publico', '/?r=reservar&s={SLUG}', 'reserva_hora', 0),
    ('05_cobrar', 'dueno', '/?r=cobrar&cita={CITA_COBRAR}', 'cobro_completo', 0),
    ('06_caja', 'dueno', '/?r=caja', None, 0),
    ('07_cuentas', 'dueno', '/?r=cuentas', None, 0),
    ('08_cliente', 'dueno', '/?r=cliente&id={CLIENTE_MARIA}', None, 0),
    ('09_reporte', 'dueno', '/?r=reporte&mes={MES}', None, 0),
    ('10_portal', 'carlos', '/?r=mi_portal&p=mes', None, 0),
]


def accion(pg, cual):
    if cual == 'cobro_completo':
        pg.evaluate("() => { const c = document.querySelector('input[name^=paquetes]'); if (c) { c.checked = true; c.dispatchEvent(new Event('change', {bubbles: true})); } }")
    elif cual == 'reserva_hora':
        pg.click("label.tarjeta:has-text('Carlos Mena')")
        pg.click('#servicio-boton')
        pg.wait_for_selector('.desplegable-lista li')
        pg.click(".desplegable-lista li:has-text('Corte y barba')")
        pg.locator('label.dia:not(.cerrado)').nth(2).click()
        pg.wait_for_selector('#horas .hora-libre', timeout=8000)
        pg.locator('#horas .hora-libre:not(.en-confirmacion)').nth(3).click()
        pg.evaluate("() => { const f = document.querySelectorAll('fieldset.paso')[1]; f.scrollIntoView(); window.scrollBy(0, -8); }")


dest = Path(carpeta) / 'cap_es'
dest.mkdir(parents=True, exist_ok=True)
fallas = []
with sync_playwright() as pw:
    nav = pw.chromium.launch(args=['--lang=es-EC'])
    sesiones = {}

    def contexto(quien):
        if quien in sesiones:
            return sesiones[quien]
        ctx = nav.new_context(viewport={'width': 412, 'height': 824}, device_scale_factor=1080 / 412, is_mobile=True, has_touch=True, locale='es-EC',
                              user_agent='Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36')
        pg = ctx.new_page()
        if quien != 'publico':
            pg.goto(url + '/?r=login&app=play')
            pg.fill('#email', CUENTAS[quien]); pg.fill('#clave', CLAVE)
            with pg.expect_navigation():
                pg.click('form button[type=submit]')
        sesiones[quien] = pg
        return pg

    for nombre, quien, ruta, acc, bajar in PANTALLAS:
        pg = contexto(quien)
        pg.goto(url + ruta.format(**D))
        pg.wait_for_load_state('networkidle')
        if acc:
            accion(pg, acc)
        if bajar:
            pg.evaluate(f'window.scrollTo(0, {bajar})')
        pg.wait_for_timeout(400)
        texto = pg.inner_text('body')
        if PRECIO_SOFTWARE.search(texto):
            fallas.append(f'{nombre}: {PRECIO_SOFTWARE.search(texto).group(0)}')
        pg.screenshot(path=str(dest / f'{nombre}.png'))
        print('listo', nombre)
    nav.close()
if fallas:
    sys.exit('Aparece un precio del software o un pago por fuera: ' + '; '.join(fallas))
