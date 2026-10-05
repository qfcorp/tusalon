// Portal de reservas: muestra las horas libres en tiempo real y las refresca cada 30 segundos.
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#form-reserva');
    if (!form) return;
    const caja = form.querySelector('#horas');
    const estado = form.querySelector('#horas-estado');
    let elegida = caja.dataset.elegida || '';
    let pedido = 0;

    const valor = n => form.querySelector(`[name="${n}"]:checked`)?.value || '';

    async function cargar() {
        const p = valor('profesional'), serv = valor('servicio'), f = valor('fecha');
        if (!p || !serv || !f) return;
        const yo = ++pedido;
        estado.textContent = 'Buscando horas libres…';
        try {
            const url = `/?r=horas&s=${encodeURIComponent(form.dataset.salon)}&p=${p}&serv=${serv}&f=${f}`;
            const r = await fetch(url, { cache: 'no-store' });
            const datos = await r.json();
            if (yo !== pedido) return;   // llegó una respuesta vieja
            caja.innerHTML = '';
            if (datos.cerrado) {
                estado.textContent = 'El salón no atiende ese día.';
                return;
            }
            const enConf = datos.en_confirmacion || [];
            if (!datos.horas.length && !enConf.length) {
                estado.textContent = 'No quedan horas libres ese día. Prueba otro día u otro peluquero.';
                return;
            }
            if (elegida && enConf.includes(elegida)) {
                estado.textContent = `Estamos confirmando las ${elegida} para otro cliente. Elige otra hora.`;
                elegida = '';
            } else if (elegida && !datos.horas.includes(elegida)) {
                estado.textContent = `La hora ${elegida} se acaba de ocupar. Elige otra.`;
                elegida = '';
            } else {
                estado.textContent = datos.horas.length
                    ? `${datos.horas.length} horas libres. Se actualiza solo.`
                    : 'Las horas de este día se están confirmando para otros clientes. Prueba otro día u otro peluquero.';
            }
            const todas = [...datos.horas.map(h => [h, true]), ...enConf.map(h => [h, false])]
                .sort((a, b) => a[0].localeCompare(b[0]));
            for (const [h, libre] of todas) {
                const l = document.createElement('label');
                l.className = libre ? 'hora-libre' : 'hora-libre en-confirmacion';
                l.innerHTML = libre
                    ? `<input type="radio" name="hora" value="${h}" required><span>${h}</span>`
                    : `<input type="radio" disabled><span>${h}<small>en confirmación</small></span>`;
                if (libre && h === elegida) l.querySelector('input').checked = true;
                if (!libre) l.addEventListener('click', () => {
                    estado.textContent = `Estamos confirmando las ${h} para otro cliente. Elige otra hora o vuelve a revisar en unos minutos.`;
                });
                caja.appendChild(l);
            }
        } catch (e) {
            estado.textContent = 'No se pudieron cargar las horas. Revisa tu conexión.';
        }
    }

    // Invitado o crear cuenta: mostrar los campos de la cuenta solo si la eligió
    const mostrarModo = () => {
        const modo = form.querySelector('[name="modo"]:checked')?.value;
        form.querySelectorAll('[data-modo]').forEach(b => {
            const visible = b.dataset.modo === modo;
            b.hidden = !visible;
            b.querySelectorAll('input[type=email], input[type=password]').forEach(i => i.required = visible);
        });
    };
    form.querySelectorAll('[name="modo"]').forEach(r => r.addEventListener('change', mostrarModo));
    mostrarModo();

    form.addEventListener('change', ev => {
        if (ev.target.name === 'hora') { elegida = ev.target.value; return; }
        if (['profesional', 'servicio', 'fecha'].includes(ev.target.name)) { elegida = ''; cargar(); }
    });
    form.addEventListener('submit', ev => {
        if (!valor('hora')) {
            ev.preventDefault();
            estado.textContent = 'Elige una hora para tu cita.';
            estado.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
    cargar();
    setInterval(() => { if (document.visibilityState === 'visible') cargar(); }, 30000);
});
