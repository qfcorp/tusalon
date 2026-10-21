#!/usr/bin/env python3
"""Capturas reales de TuSalón con el salón de ejemplo (docs/manual/generar/demo.php) para el manual.
Uso: python3 docs/manual/generar/capturas.py --base http://127.0.0.1:8231 --salida docs/manual/capturas --datos datos.env [--solo n1,n2]
Entra como dueña, recepción, peluquero y cliente, y guarda cada pantalla en celular (390 px) o computadora (1280 px).
Reglas: ninguna captura muestra precios del software. En «Mi plan» se ocultan «Lo que pagas al mes» (#total) y el pago con
Google Play (#pago-play) antes de la foto; si quedara algún precio del plan a la vista, el script avisa y termina con error.
Usa el Chromium que ya está instalado (PLAYWRIGHT_BROWSERS_PATH, por defecto /opt/pw-browsers); nunca corre «playwright install»."""
import argparse, os, re, sys
from pathlib import Path
from playwright.sync_api import sync_playwright

ap = argparse.ArgumentParser()
ap.add_argument('--base', required=True)
ap.add_argument('--salida', required=True)
ap.add_argument('--datos', required=True, help='archivo clave=valor que imprime demo.php')
ap.add_argument('--clave', default='SalonDemo2026')
ap.add_argument('--solo', default='')
a = ap.parse_args()
SAL = Path(a.salida); SAL.mkdir(parents=True, exist_ok=True)
SOLO = set(x for x in a.solo.split(',') if x)
B = a.base.rstrip('/')
D = dict(l.strip().split('=', 1) for l in Path(a.datos).read_text().splitlines() if '=' in l)

CUENTAS = {'dueno': 'demo@tusalon.tukanec.com', 'recepcion': 'recepcion@tusalon.tukanec.com', 'carlos': 'carlos@tusalon.tukanec.com',
           'jorge': 'jorge@tusalon.tukanec.com', 'basica': 'basica@tusalon.tukanec.com', 'endemo': 'endemo@tusalon.tukanec.com'}
OCULTAR_PLAN = '#total, #pago-play'   # precios del software: nunca en las capturas

# (archivo, quién entra, ruta, ancho, acción antes de la foto, página completa)
PANTALLAS = [
    ('01_entrar_celular', 'nadie', '/?r=login', 390, None, False),
    ('02_solicitar_demo', 'nadie', '/?r=registro', 1280, None, False),
    ('03_inicio_celular', 'dueno', '/?r=inicio', 390, None, False),
    ('04_inicio_compu', 'dueno', '/?r=inicio', 1280, None, False),
    ('05_equipo', 'dueno', '/?r=equipo', 1280, None, False),
    ('06_horario_peluquero', 'dueno', '/?r=horario_peluquero&p={CARLOS}', 1280, None, False),
    ('07_servicios', 'dueno', '/?r=servicios', 1280, None, False),
    ('08_servicio_quien', 'dueno', '/?r=servicios', 1280, 'abrir_keratina', False),
    ('09_horario_reservas', 'dueno', '/?r=horario', 1280, None, False),
    ('10_reglas_reservas', 'dueno', '/?r=horario', 1280, 'ir_reglas', False),
    ('11_anticipo', 'dueno', '/?r=horario', 1280, 'ir_anticipo', False),
    ('12_agenda_compu', 'dueno', '/?r=agenda&fecha={MANANA}', 1280, None, False),
    ('13_agenda_celular', 'dueno', '/?r=agenda&fecha={MANANA}', 390, None, False),
    ('14_cita_nueva_celular', 'dueno', '/?r=cita_nueva&fecha={MANANA}&hora=13:00', 390, None, False),
    ('15_cita', 'dueno', '/?r=cita&id={CITA_ABIERTA}', 1280, None, False),
    ('16_solicitudes', 'dueno', '/?r=solicitudes', 1280, None, False),
    ('17_revisar_anticipo', 'dueno', '/?r=cita&id={CITA_ANTICIPO}', 1280, None, True),
    ('18_reservar_servicio', 'publico', '/?r=reservar&s={SLUG}', 390, 'reserva_servicio', False),
    ('19_reservar_hora', 'publico', '/?r=reservar&s={SLUG}', 390, 'reserva_hora', False),
    ('20_reservar_listo', 'publico', '/?r=reservar&s={SLUG}', 390, 'reserva_enviar', False),
    ('21_cita_del_cliente', 'publico', '/?r=confirmar&t={TOKEN_CITA}', 390, None, False),
    ('22_calificar', 'publico', '/?r=confirmar&t={TOKEN_ATENDIDA}', 390, 'bajar_calificar', False),
    ('23_cobrar', 'dueno', '/?r=cobrar&cita={CITA_COBRAR}', 1280, 'cobro_completo', True),
    ('24_caja', 'dueno', '/?r=caja', 1280, None, True),
    ('25_cuentas', 'dueno', '/?r=cuentas', 1280, None, False),
    ('26_clientes_celular', 'dueno', '/?r=clientes', 390, None, False),
    ('27_cliente', 'dueno', '/?r=cliente&id={CLIENTE_MARIA}', 1280, None, False),
    ('28_ficha_tecnica', 'dueno', '/?r=ficha&cliente={CLIENTE_MARIA}', 1280, None, False),
    ('29_avisos_clientes', 'dueno', '/?r=avisos_clientes', 1280, None, True),
    ('30_inventario', 'dueno', '/?r=inventario', 1280, None, False),
    ('31_paquetes', 'dueno', '/?r=paquetes', 1280, None, True),
    ('32_tarjeta_regalo', 'dueno', '/?r=tarjetas&ver={TARJETA}', 1280, None, False),
    ('33_puntos', 'dueno', '/?r=puntos', 1280, None, False),
    ('34_reporte', 'dueno', '/?r=reporte&mes={MES}', 1280, None, True),
    ('35_recepcion', 'dueno', '/?r=recepcion', 1280, None, False),
    ('36_recepcion_inicio_celular', 'recepcion', '/?r=inicio', 390, None, False),
    ('37_portal_peluquero_celular', 'carlos', '/?r=mi_portal&p=mes', 390, None, False),
    ('38_mis_clientes_celular', 'jorge', '/?r=mis_clientes', 390, None, False),
    ('39_mi_plan_compu', 'basica', '/?r=completa', 1280, 'ocultar_precios', False),
    ('40_mi_plan_celular', 'basica', '/?r=completa', 390, 'ocultar_precios_bajar', False),
    ('41_no_en_plan_celular', 'basica', '/?r=inventario', 390, None, False),
]


def accion(pg, cual):
    if cual == 'abrir_keratina':
        pg.evaluate("""() => { for (const d of document.querySelectorAll('table.tabla details')) {
            if (d.querySelector('summary').textContent.includes('Keratina')) { d.open = true; d.scrollIntoView(); window.scrollBy(0, -20); } } }""")
    elif cual == 'ir_reglas':
        pg.evaluate("() => { for (const h of document.querySelectorAll('h2')) if (h.textContent.includes('Cuando un cliente reserva')) { h.scrollIntoView(); window.scrollBy(0, -16); } }")
    elif cual == 'ir_anticipo':
        pg.evaluate("() => { const f = document.getElementById('anticipo'); f.scrollIntoView(); window.scrollBy(0, -16); }")
    elif cual == 'bajar_calificar':
        pg.evaluate("() => { const f = document.querySelector('fieldset.calificar'); if (f) { f.closest('section').scrollIntoView(); window.scrollBy(0, -10); } }")
        pg.click('label[for=estrella5]')
    elif cual == 'cobro_completo':
        # Pedro usa una sesión de su paquete y lleva un producto
        pg.evaluate("() => { const c = document.querySelector('input[name^=paquetes]'); if (c) { c.checked = true; c.dispatchEvent(new Event('change', {bubbles: true})); } }")
        pg.evaluate("() => { const d = document.querySelector('details.campo'); if (d) d.open = true; }")
        pg.fill(f"input[name='productos[{D['PROD_SHAMPOO']}]']", '1')
        pg.dispatch_event(f"input[name='productos[{D['PROD_SHAMPOO']}]']", 'input')
    elif cual in ('ocultar_precios', 'ocultar_precios_bajar'):
        pg.add_style_tag(content=OCULTAR_PLAN + ' { display: none !important; }')
        if cual.endswith('bajar'):
            pg.evaluate("() => { const s = document.getElementById('agregar'); if (s) { s.scrollIntoView(); window.scrollBy(0, -10); } }")
    elif cual.startswith('reserva_'):
        pg.click("label.tarjeta:has-text('Carlos Mena')")
        pg.click('#servicio-boton')
        pg.wait_for_selector('.desplegable-lista li')
        if cual == 'reserva_servicio':
            li = pg.locator(".desplegable-lista li:has-text('Corte y barba')")
            li.locator('.op-info').click()
            pg.evaluate("() => { const f = document.querySelectorAll('fieldset.paso')[1]; f.scrollIntoView(); window.scrollBy(0, -8); }")
            return
        pg.click(".desplegable-lista li:has-text('Corte y barba')")
        dias = pg.locator('label.dia:not(.cerrado)')
        dias.nth(2).click()
        pg.wait_for_selector('#horas .hora-libre', timeout=8000)
        if cual == 'reserva_hora':
            pg.locator('#horas .hora-libre:not(.en-confirmacion)').nth(3).click()
            pg.evaluate("() => { const f = document.querySelectorAll('fieldset.paso')[2]; f.scrollIntoView(); window.scrollBy(0, -8); }")
            return
        pg.locator('#horas .hora-libre:not(.en-confirmacion)').nth(5).click()
        pg.fill('#nombre', 'Esteban Pazmiño')
        pg.fill('#telefono', '0961234567')
        with pg.expect_navigation():
            pg.click('#btn-reservar')
        pg.wait_for_load_state('networkidle')


PRECIO_SOFTWARE = re.compile(r'(?i)(plan[^$\n]{0,40}\$\s?\d|\$\s?\d[\d.,]*\s*(al mes|/mes|mensual)|gratis|gratuit)')


def main():
    sql_cita_cobrar = D.get('CITA_COBRAR')
    if not sql_cita_cobrar:
        import subprocess
        q = lambda s: subprocess.run(['psql', '-h', '127.0.0.1', '-U', os.environ.get('TUSALON_DB_USER', 'tusalon'), '-d', os.environ['TUSALON_DB_NAME'], '-tAc', s],
                                     capture_output=True, text=True, env={**os.environ, 'PGPASSWORD': os.environ.get('TUSALON_DB_PASS', 'prueba')}).stdout.strip()
        D['CITA_COBRAR'] = q(f"SELECT id FROM citas WHERE salon_id={D['SALON']} AND cliente_id={D['PEDRO']} AND estado IN ('reservada','confirmada') ORDER BY inicio LIMIT 1")
    fallas, avisos = [], []
    with sync_playwright() as p:
        nav = p.chromium.launch(args=['--lang=es-EC'])
        sesiones = {}

        def contexto(quien, ancho):
            k = (quien, ancho)
            if k in sesiones and quien != 'publico':
                return sesiones[k]
            movil = ancho < 600
            ctx = nav.new_context(viewport={'width': ancho, 'height': 844 if movil else 820}, device_scale_factor=2 if movil else 1.5,
                                  locale='es-EC', is_mobile=movil, has_touch=movil, timezone_id='America/Guayaquil')
            pg = ctx.new_page()
            pg.on('pageerror', lambda e: fallas.append(f'error de JavaScript: {e}'))
            if quien in CUENTAS:
                pg.goto(B + '/?r=login'); pg.fill('#email', CUENTAS[quien]); pg.fill('#clave', a.clave)
                with pg.expect_navigation():
                    pg.click('form button[type=submit]')
            sesiones[k] = pg
            return pg

        for nombre, quien, ruta, ancho, acc, completa in PANTALLAS:
            if SOLO and nombre not in SOLO:
                continue
            pg = contexto(quien, ancho)
            pg.goto(B + ruta.format(**D)); pg.wait_for_load_state('networkidle')
            if acc:
                accion(pg, acc)
            if completa:   # el menú es fijo: en una foto de página completa va pegado al cuerpo, no a la ventana
                pg.add_style_tag(content='body { position: relative; } .lateral { position: absolute !important; }')
            pg.wait_for_timeout(350)
            if pg.evaluate('() => document.documentElement.scrollWidth > window.innerWidth + 1'):
                avisos.append(f'{nombre}: la página se sale a lo ancho')
            visible = pg.evaluate("""() => [...document.querySelectorAll('body *')].filter(e => e.offsetParent !== null && e.children.length === 0)
                                         .map(e => e.textContent).join('\\n')""")
            for m in PRECIO_SOFTWARE.finditer(visible):
                fallas.append(f'{nombre}: se ve un precio del software o la palabra prohibida: «{m.group(0)}»')
            pg.screenshot(path=str(SAL / f'{nombre}.png'), full_page=completa)
            print('captura', nombre)
        nav.close()
    for f in avisos:
        print('AVISO:', f)
    if fallas:
        print('\n'.join('FALLA: ' + f for f in fallas))
        sys.exit(1)


main()
