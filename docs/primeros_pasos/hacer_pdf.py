"""Primeros pasos de TuSalón (guía corta para el cliente, PDF A4).
Uso: python3 hacer_pdf.py   ->  ../TuSalon_Primeros_Pasos.pdf"""
import os
from playwright.sync_api import sync_playwright
D = os.path.dirname(os.path.abspath(__file__))
CAP = os.path.join(os.path.dirname(D), 'manual')
u = lambda p: 'file://' + p
img = lambda n: u(os.path.join(CAP, n))
NAVY, SAND, TEAL, GOLD = '#14213D', '#F5F1EA', '#2A9D8F', '#FFC93C'

def paso(n, titulo, donde, items, consejo=''):
    lis = ''.join(f'<li>{x}</li>' for x in items)
    c = f'<p class="tip"><b>Consejo:</b> {consejo}</p>' if consejo else ''
    return f'<div class="paso"><div class="num">{n}</div><div class="cuerpo"><h3>{titulo}</h3><p class="donde">{donde}</p><ol>{lis}</ol>{c}</div></div>'

css = f"""
@font-face{{font-family:SG;src:url('{u(D)}/fonts/space-grotesk-latin-700-normal.woff2')}}
@font-face{{font-family:DM;src:url('{u(D)}/fonts/dm-sans-latin-400-normal.woff2')}}
@page{{size:A4;margin:16mm 15mm 18mm}}
*{{box-sizing:border-box}} body{{font-family:DM,Arial,sans-serif;color:{NAVY};font-size:10.5pt;line-height:1.42;margin:0}}
h1{{font-family:SG;font-size:30pt;margin:6mm 0 1mm;letter-spacing:-.5px}} h2{{font-family:SG;font-size:15pt;margin:7mm 0 3mm;color:{NAVY};border-bottom:3px solid {TEAL};padding-bottom:1.5mm}}
h3{{font-family:SG;font-size:12pt;margin:0 0 .5mm}}
.top{{display:flex;justify-content:space-between;align-items:center}} .top img{{height:11mm}}
.chip{{display:inline-block;background:{GOLD};font-family:SG;font-size:9pt;padding:1mm 3mm;border-radius:99px}}
.lead{{font-size:11.5pt;color:#33405C;margin:1mm 0 0}}
.paso{{display:flex;gap:4mm;margin:0 0 4mm;break-inside:avoid}}
.num{{min-width:9mm;height:9mm;border-radius:2.5mm;background:{TEAL};color:#fff;font-family:SG;font-size:13pt;text-align:center;line-height:9mm}}
.cuerpo{{flex:1}} .donde{{margin:0 0 1mm;color:{TEAL};font-weight:bold;font-size:9.5pt}}
ol{{margin:0;padding-left:5mm}} li{{margin:.4mm 0}}
.tip{{background:{SAND};border-left:4px solid {GOLD};padding:1.5mm 3mm;margin:1.5mm 0 0;font-size:9.5pt}}
.box{{background:{SAND};border-radius:3mm;padding:3mm 4mm;margin:3mm 0;break-inside:avoid}}
.fotos{{display:flex;gap:4mm;justify-content:center;margin:2mm 0 4mm;break-inside:avoid}}
.fotos figure{{margin:0;text-align:center;font-size:8.5pt;color:#4A5470}}
.fotos img{{border:1px solid #DED8CC;border-radius:2mm}}
.fin{{background:{NAVY};color:#fff;border-radius:3mm;padding:4mm 5mm;margin-top:5mm;break-inside:avoid}}
.fin b{{color:{GOLD}}}
"""

h = []
h.append(f'''<div class="top"><img src="{u(D)}/logo_oscuro.png"><span class="chip">Guía rápida</span></div>
<h1>Primeros pasos en TuSalón</h1>
<p class="lead">Lo básico para empezar a trabajar hoy. Para todo lo demás está el <b>Manual de TuSalón</b>.</p>
<div class="box"><b>Antes de empezar:</b> lo que ve en el menú depende de los módulos que contrató.
En el <b>celular</b>, el menú está abajo (Hoy, Agenda, Solicitudes, Caja y <b>Más</b>); en la <b>computadora</b>, a la izquierda.
La configuración se hace más cómoda en la computadora. Sus peluqueros solo ven sus citas y lo que ganan, no la caja.</div>''')

h.append('<h2>Parte 1 · Deje listo su salón (una sola vez)</h2>')
h.append(paso(1, 'Entre al programa', 'La dirección, el correo y la contraseña se los enviamos nosotros', [
    'Abra la dirección de su salón en el navegador (Chrome o Safari, en el celular o la computadora).',
    'Escriba su <b>Correo</b> y su <b>Contraseña</b>, y toque <b>Entrar</b>.',
    'Llega a <b>Hoy</b>: los botones <b>Nueva cita</b> y <b>Cobrar</b>, lo que entró a la caja, las citas que faltan y las <b>Próximas citas</b>.'],
    'en el celular, agréguelo a la pantalla de inicio: así se abre como una aplicación.'))
h.append(paso(2, 'Sus servicios y precios', 'Menú Más → Servicios (en la computadora: Servicios y precios)', [
    'Al final está <b>Agregar servicio</b>: escriba el <b>Nombre del servicio</b>, el <b>Precio al cliente</b> y la <b>Duración (minutos)</b>.',
    'Si le paga un valor fijo al peluquero por hacerlo, póngalo en <b>Pago al peluquero</b>; si lo deja vacío, se usa su comisión.',
    'En <b>¿Quién lo hace?</b> desmarque a quien no hace ese servicio. Si alguien lo cobra distinto, escriba su precio al lado.',
    'Toque <b>Agregar servicio</b>. Para cambiar uno, tóquelo en la lista y luego <b>Guardar cambios</b>.']))
h.append(paso(3, 'Su equipo', 'Menú Equipo (en el celular: Más)', [
    'En <b>Agregar peluquero</b> ponga el <b>Nombre</b> y el <b>Celular</b>.',
    'Elija <b>¿Cómo trabaja?</b>: <b>Empleado</b> (sueldo, comisión o las dos), <b>Por porcentaje</b> (50/50, 60/40…) o <b>Alquila el puesto</b> (le paga un arriendo).',
    'Llene sus valores (comisión, porcentaje o arriendo) y toque <b>Agregar al equipo</b>.',
    'Si quiere que vea sus citas en su celular, toque <b>Darle acceso a su portal</b>, escriba su correo y una contraseña, y toque <b>Crear acceso</b>.']))
h.append(f'''<div class="fotos"><figure><img src="{img('02_inicio_celular.jpg')}" style="height:62mm"><figcaption>Hoy, en el celular</figcaption></figure>
<figure><img src="{img('08b_agenda_compu.jpg')}" style="height:62mm"><figcaption>La agenda, con una columna por peluquero</figcaption></figure></div>''')
h.append(paso(4, 'Horario de cada peluquero', 'Menú Equipo → Horario, almuerzo y vacaciones', [
    'Elija <b>Usa el horario del salón</b> o <b>Tiene su propio horario</b> (días libres, hora de entrada y salida, almuerzo).',
    'Toque <b>Guardar horario</b>.',
    'Para vacaciones o un permiso, use <b>Vacaciones, permisos o días libres</b> y toque <b>Bloquear esas fechas</b>.']))
h.append(paso(5, 'Horario del salón y reservas por internet', 'Menú Más → Horario y reservas (en la computadora: Horario y reservas en línea)', [
    'En <b>Días y horas de atención</b> marque los días que abre y la hora de entrada y salida.',
    'Elija <b>¿Quién acepta la cita?</b> cuando un cliente reserva en línea, y las <b>Reglas para tus clientes</b> (hasta cuándo puede cancelar, faltas).',
    'Toque <b>Guardar</b>. Arriba está <b>Tu página de reservas</b>: compártala con <b>Compartir por WhatsApp</b> y en sus redes.']))

h.append('<h2>Parte 2 · El día a día</h2>')
h.append(paso(6, 'Agendar una cita', 'Botón Nueva cita (en Hoy o en Agenda)', [
    'Ponga el <b>Día</b>, la <b>Hora</b> y <b>Con quién</b> (el peluquero que atiende).',
    'Elija el <b>Cliente</b> de la lista. Si es nuevo, escriba su nombre y su celular para el WhatsApp.',
    'Marque los <b>Servicios</b> (el precio se puede cambiar solo para este cliente) y toque <b>Agendar cita</b>.',
    'El programa no deja que se crucen dos citas del mismo peluquero.'],
    'en <b>Agenda</b> también puede tocar un espacio libre para agendar.'))
h.append(paso(7, 'Aceptar las reservas por internet', 'Menú Solicitudes', [
    'Cuando un cliente reserva desde su enlace, aparece una franja amarilla en <b>Hoy</b> y un número en <b>Solicitudes</b>.',
    'Revise el <b>Valor a cobrar</b> y toque <b>Aceptar</b>, o <b>Rechazar</b> si no puede atenderlo.',
    'Al aceptar, toque <b>Enviar WhatsApp al cliente</b> para mandarle la confirmación con un toque.']))
h.append(f'''<div class="fotos"><figure><img src="{img('09_solicitud.jpg')}" style="height:48mm"><figcaption>Una solicitud por responder</figcaption></figure></div>''')
h.append(paso(8, 'Cobrar', 'Abra la cita → Cobrar (o botón Cobrar en Hoy, para alguien sin cita)', [
    'Elija <b>Quién atendió</b>. Los <b>Servicios</b> de la cita ya vienen marcados; puede agregar otro o cambiar el precio.',
    'Elija <b>Cómo pagó lo que falta</b> (efectivo, transferencia, DeUna, tarjeta…) y, si hay, la <b>Propina</b>.',
    'En <b>¿Quién recibió el dinero?</b> elija <b>La caja del local</b> o <b>El peluquero directamente</b>.',
    'Toque <b>Registrar cobro</b>. El programa calcula solo cuánto le toca a cada peluquero.']))
h.append(paso(9, 'Cerrar la caja del día', 'Menú Caja', [
    'Revise los <b>Cobros del día</b> y cuánto entró <b>Por forma de pago</b>.',
    'Al final del día cuente el efectivo y escríbalo en <b>¿Cuánto efectivo hay en la caja?</b>.',
    'Toque <b>Cerrar caja</b>: el programa le dice si sobra o falta dinero.']))
h.append(paso(10, 'Pagar a su equipo', 'Menú Más → Cuentas (en la computadora: Cuentas del equipo)', [
    'Vea cuánto le debe a cada peluquero, o cuánto le debe él a usted (por ejemplo, el arriendo de la silla).',
    'Toque <b>Ver detalle</b> para revisar cada cobro y, cuando ya pagaron, <b>Pagado, cerrar cuenta</b>.']))
h.append(paso(11, 'Sus administradores (si los necesita)', 'Menú Más → Administradores (o Equipo → Ver y crear administradores)', [
    'Para un socio, gerente o encargado que maneje todo como usted: escriba su <b>Nombre</b>, el <b>Correo con el que entra</b> y una contraseña, y toque <b>Crear administrador</b>.',
    'Tiene su mismo nivel en todo el programa. Puede tener <b>hasta 4</b> además de usted, y <b>no cuentan</b> en el límite de personas del salón.',
    'Puede <b>Editar</b>, <b>Cambiar contraseña</b>, <b>Desactivar</b> y <b>Activar</b> cada cuenta. Su cuenta de dueño nadie más la puede cambiar.'],
    'si su administrador también atiende clientes, agréguelo además en Equipo como peluquero.'))

h.append('''<div class="fin"><b>¿Listo? Ya puede trabajar.</b> Para clientes, avisos, inventario, paquetes, reportes y todo lo demás,
vea el <b>Manual de TuSalón</b>.<br><br>¿No sabe cómo hacer algo? Toque <b>Ayuda</b> (en el celular, en <b>Más</b>) y pregúntelo con sus palabras: le responde con los pasos del manual.<br><br>¿Dudas? Escríbanos por <b>WhatsApp 099 640 8397</b> · info@tukansoftware.com · tukansoftware.com</div>''')

html = f'<!doctype html><html lang="es"><head><meta charset="utf-8"><style>{css}</style></head><body>{"".join(h)}</body></html>'
open(f'{D}/primeros_pasos.html', 'w').write(html)
out = os.path.join(os.path.dirname(D), 'TuSalon_Primeros_Pasos.pdf')
with sync_playwright() as pw:
    b = pw.chromium.launch(); pg = b.new_page()
    pg.goto(u(f'{D}/primeros_pasos.html')); pg.wait_for_load_state('networkidle'); pg.evaluate('document.fonts.ready')
    pg.pdf(path=out, format='A4', print_background=True, display_header_footer=True, header_template='<span></span>',
           footer_template='<div style="font-size:8px;color:#4A5470;width:100%;text-align:center">TuSalón · Primeros pasos · página <span class="pageNumber"></span> de <span class="totalPages"></span></div>',
           margin={'top': '16mm', 'bottom': '18mm', 'left': '15mm', 'right': '15mm'})
    b.close()
print('ok', out)
