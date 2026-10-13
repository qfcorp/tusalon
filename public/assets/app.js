// TuSalón — comportamientos pequeños, sin librerías.
document.addEventListener('DOMContentLoaded', () => {
    // Mostrar los campos de pago según el tipo de peluquero elegido
    const tipos = document.querySelectorAll('input[name="tipo"]');
    const mostrarTipo = () => {
        const elegido = document.querySelector('input[name="tipo"]:checked');
        document.querySelectorAll('[data-tipo]').forEach(b => {
            b.classList.toggle('oculto', !elegido || b.dataset.tipo !== elegido.value);
        });
    };
    tipos.forEach(t => t.addEventListener('change', mostrarTipo));
    mostrarTipo();

    // Cobrar: sumar el total al marcar servicios o cambiar precios
    const formCobro = document.querySelector('#form-cobro');
    if (formCobro) {
        const total = formCobro.querySelector('#total-cobro');
        const totalServ = formCobro.querySelector('#total-servicios');
        const filaPre = formCobro.querySelector('#fila-prepago');
        const totalPre = formCobro.querySelector('#total-prepago');
        const notaTarjeta = formCobro.querySelector('#nota-tarjeta');
        const valorPunto = parseFloat(formCobro.dataset.valorPunto || '0');
        const anticipo = parseFloat(formCobro.dataset.anticipo || '0');
        const plata = n => '$' + n.toFixed(2).replace('.', ',');
        const recalcular = () => {
            let suma = 0, prepago = 0;
            formCobro.querySelectorAll('[data-servicio]').forEach(fila => {
                const marcado = fila.querySelector('input[name="servicio[]"]').checked;
                let precio = parseFloat(fila.querySelector('input[name^=precio]').value || '0');
                const paq = fila.querySelector('input[name^=paquetes]:checked');
                if (marcado && paq) { precio = parseFloat(paq.dataset.valorSesion || '0'); prepago += precio; }
                if (marcado) suma += precio;
            });
            formCobro.querySelectorAll('[data-producto]').forEach(fila => {
                suma += parseFloat(fila.dataset.precio || '0') * parseInt(fila.querySelector('input').value || '0', 10);
            });
            let falta = Math.max(0, suma - prepago);
            const usarAnt = Math.min(anticipo, falta); prepago += usarAnt; falta -= usarAnt;
            const pts = formCobro.querySelector('[name=puntos]');
            if (pts && !pts.disabled && valorPunto > 0) {
                const desc = Math.min(parseInt(pts.value || '0', 10) * valorPunto, falta);
                prepago += desc; falta -= desc;
            }
            const cod = formCobro.querySelector('[name=tarjeta_codigo]');
            if (notaTarjeta) notaTarjeta.hidden = !(cod && cod.value.trim());
            const propina = parseFloat(formCobro.querySelector('[name=propina]').value || '0');
            if (totalServ) totalServ.textContent = plata(suma);
            if (filaPre) { filaPre.hidden = totalPre.hidden = prepago <= 0; totalPre.textContent = '−' + plata(prepago); }
            total.textContent = plata(falta + propina);
        };
        formCobro.addEventListener('input', recalcular);
        formCobro.addEventListener('change', recalcular);
        recalcular();

        // Sugerir quién cobra según el tipo del peluquero
        const prof = formCobro.querySelector('[name=profesional_id]');
        prof?.addEventListener('change', () => {
            const op = prof.selectedOptions[0];
            const quien = op?.dataset.cobra || 'local';
            const radio = formCobro.querySelector(`[name=cobrado_por][value=${quien}]`);
            if (radio) radio.checked = true;
        });
    }

    // Confirmación antes de acciones que no se pueden deshacer
    document.querySelectorAll('[data-confirmar]').forEach(el => {
        el.addEventListener('click', ev => {
            if (!confirm(el.dataset.confirmar)) ev.preventDefault();
        });
    });

    // Línea de "ahora" en la agenda
    const agenda = document.querySelector('.agenda[data-hoy="1"]');
    if (agenda) {
        const inicio = parseInt(agenda.dataset.horaInicio, 10);
        const fin = parseInt(agenda.dataset.horaFin, 10);
        const alto = parseFloat(getComputedStyle(agenda).getPropertyValue('--alto-hora')) || 72;
        const ponerAhora = () => {
            const d = new Date();
            const horas = d.getHours() + d.getMinutes() / 60;
            agenda.querySelectorAll('.ahora').forEach(l => l.remove());
            if (horas < inicio || horas > fin) return;
            agenda.querySelectorAll('.columna').forEach(col => {
                const linea = document.createElement('div');
                linea.className = 'ahora';
                linea.style.top = ((horas - inicio) * alto) + 'px';
                col.appendChild(linea);
            });
        };
        ponerAhora();
        setInterval(ponerAhora, 60000);
    }

    // Panel Tukán: registrar pago — muestra el precio normal y el dominio solo en el anual
    const formPago = document.querySelector('#form-pago');
    if (formPago) {
        let cot = {};
        try { cot = JSON.parse(formPago.dataset.cotizaciones || '{}'); } catch (e) { /* nada */ }
        const plan = formPago.querySelector('#plan'), periodo = formPago.querySelector('#periodo');
        const monto = formPago.querySelector('#monto'), ayuda = formPago.querySelector('#monto-ayuda');
        const dominio = formPago.querySelector('#campo-dominio');
        const actualizar = () => {
            const c = cot[plan.value]?.[periodo.value];
            if (c) {
                monto.placeholder = Number(c.monto).toFixed(2);
                ayuda.textContent = `Vacío = precio normal ($${Number(c.monto).toFixed(2).replace('.', ',')} por ${c.meses_recibidos} ${c.meses_recibidos === 1 ? 'mes' : 'meses'}).`;
            }
            dominio.hidden = periodo.value !== 'anual';
        };
        plan.addEventListener('change', actualizar);
        periodo.addEventListener('change', actualizar);
        actualizar();
    }
});

// ---------------------------------------------------------------
// App en el celular (se instala desde el navegador, sin tiendas)
// ---------------------------------------------------------------
if ('serviceWorker' in navigator && document.body.classList.contains('con-sesion')) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}
(() => {
    const caja = document.querySelector('[data-instalar-app]');
    if (!caja) return;
    const enApp = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (enApp) { caja.hidden = true; return; }
    const boton = caja.querySelector('[data-instalar]');
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const ayudaIos = caja.querySelector('[data-ayuda-ios]');
    const ayudaOtro = caja.querySelector('[data-ayuda-otro]');
    let pedido = null;
    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        pedido = e;
        caja.hidden = false;
        if (boton) boton.hidden = false;
        if (ayudaOtro) ayudaOtro.hidden = true;
    });
    if (ios && ayudaIos) { ayudaIos.hidden = false; if (ayudaOtro) ayudaOtro.hidden = true; }
    boton?.addEventListener('click', async () => {
        if (!pedido) return;
        pedido.prompt();
        const r = await pedido.userChoice;
        if (r.outcome === 'accepted') caja.hidden = true;
        pedido = null;
    });
    window.addEventListener('appinstalled', () => { caja.hidden = true; });
})();
