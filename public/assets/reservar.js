// Portal de reservas:
//  - desplegable de servicios con detalle (al dejar el mouse 3 segundos, o tocando ⓘ en el celular)
//  - servicios y días según el peluquero elegido (con su precio)
//  - horas libres en tiempo real, se refrescan cada 30 segundos
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#form-reserva');
    if (!form) return;
    const caja = form.querySelector('#horas');
    const estado = form.querySelector('#horas-estado');
    const select = form.querySelector('#servicio');
    const elegidoCaja = form.querySelector('#servicio-elegido');
    let datos = { porProfesional: {}, dias: {} };
    try { datos = JSON.parse(form.querySelector('#datos-reserva')?.textContent || '{}'); } catch (e) { /* sin datos */ }
    let elegida = caja.dataset.elegida || '';
    let pedido = 0;
    const ESPERA_DETALLE = 3000;   // milisegundos con el mouse encima para ver el detalle

    const valor = n => n === 'servicio' ? select.value : (form.querySelector(`[name="${n}"]:checked`)?.value || '');
    const dinero = n => {
        const [ent, dec] = Number(n).toFixed(2).split('.');
        return '$' + ent.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + dec;
    };
    const duracion = m => {
        m = Number(m);
        if (m < 60) return `${m} minutos`;
        const h = Math.floor(m / 60), r = m % 60;
        return `${h} ${h === 1 ? 'hora' : 'horas'}${r ? ` y ${r} minutos` : ''}`;
    };
    const textoSeguro = t => { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; };

    // ------------------------------------------------------------------
    // Desplegable de servicios
    // ------------------------------------------------------------------
    const servicios = [...select.options].filter(o => o.value).map(o => ({
        id: o.value,
        nombre: o.textContent.split(' · ')[0].trim(),
        min: o.dataset.min,
        precioBase: Number(o.dataset.precio),
        desc: o.dataset.desc || '',
    }));
    const precioDe = (s, prof) => {
        const lista = datos.porProfesional?.[prof];
        return lista && lista[s.id] !== undefined ? Number(lista[s.id]) : s.precioBase;
    };
    const loHace = (s, prof) => !prof || !!(datos.porProfesional?.[prof] && datos.porProfesional[prof][s.id] !== undefined);

    const caja2 = document.createElement('div');
    caja2.className = 'desplegable';
    caja2.innerHTML = `
        <button type="button" class="desplegable-boton" id="servicio-boton" aria-haspopup="listbox" aria-expanded="false">
            <span class="desplegable-texto">Elige un servicio…</span><span aria-hidden="true">▾</span></button>
        <ul class="desplegable-lista" role="listbox" tabindex="-1" aria-labelledby="servicio-boton" hidden></ul>`;
    select.after(caja2);
    select.hidden = true;
    form.querySelector('label[for="servicio"]')?.setAttribute('for', 'servicio-boton');
    const boton = caja2.querySelector('.desplegable-boton');
    const lista = caja2.querySelector('.desplegable-lista');
    let activo = -1;
    let temporizador = null;

    function pintarLista() {
        const prof = valor('profesional');
        lista.innerHTML = '';
        servicios.forEach((s, i) => {
            const li = document.createElement('li');
            const puede = loHace(s, prof);
            li.id = `op-servicio-${s.id}`;
            li.setAttribute('role', 'option');
            li.dataset.i = i;
            li.setAttribute('aria-selected', select.value === s.id ? 'true' : 'false');
            if (!puede) li.setAttribute('aria-disabled', 'true');
            li.innerHTML = `
                <div class="op-fila">
                    <span class="op-nombre">${textoSeguro(s.nombre)}<small>${duracion(s.min)}${puede ? '' : ' · no lo hace este peluquero'}</small></span>
                    <strong class="op-precio">${dinero(precioDe(s, prof))}</strong>
                    <button type="button" class="op-info" aria-label="Ver detalle de ${textoSeguro(s.nombre)}" tabindex="-1">ⓘ</button>
                </div>
                <div class="op-detalle" hidden>
                    ${s.desc ? `<p>${textoSeguro(s.desc)}</p>` : '<p class="suave">Pregunta al salón por más detalles.</p>'}
                    <p class="op-tiempo">⏱ Dura aproximadamente <strong>${duracion(s.min)}</strong></p>
                </div>`;
            lista.appendChild(li);
        });
    }

    const opciones = () => [...lista.querySelectorAll('[role=option]')];
    const mostrarDetalle = (li, si) => {
        opciones().forEach(o => { if (o !== li) { o.querySelector('.op-detalle').hidden = true; o.classList.remove('con-detalle'); } });
        li.querySelector('.op-detalle').hidden = !si;
        li.classList.toggle('con-detalle', si);
        if (si) li.scrollIntoView({ block: 'nearest' });   // que el detalle no quede cortado abajo
    };
    const cancelarEspera = li => { clearTimeout(temporizador); li?.classList.remove('esperando'); };

    function abrir() {
        pintarLista();
        lista.hidden = false;
        boton.setAttribute('aria-expanded', 'true');
        activo = Math.max(0, servicios.findIndex(s => s.id === select.value));
        marcarActivo();
        lista.focus();
    }
    function cerrar(devolverFoco = true) {
        lista.hidden = true;
        boton.setAttribute('aria-expanded', 'false');
        clearTimeout(temporizador);
        if (devolverFoco) boton.focus();
    }
    function marcarActivo() {
        opciones().forEach((o, i) => o.classList.toggle('activo', i === activo));
        const o = opciones()[activo];
        if (o) { lista.setAttribute('aria-activedescendant', o.id); o.scrollIntoView({ block: 'nearest' }); }
    }
    function elegir(i) {
        const s = servicios[i];
        if (!s || !loHace(s, valor('profesional'))) return;
        select.value = s.id;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        cerrar();
    }
    function pintarElegido() {
        const s = servicios.find(x => x.id === select.value);
        const prof = valor('profesional');
        boton.querySelector('.desplegable-texto').innerHTML = s
            ? `<strong>${textoSeguro(s.nombre)}</strong> · ${s.min} min · ${dinero(precioDe(s, prof))}`
            : 'Elige un servicio…';
        boton.classList.toggle('vacio', !s);
        elegidoCaja.hidden = !s;
        if (s) {
            elegidoCaja.innerHTML = (s.desc ? `<p>${textoSeguro(s.desc)}</p>` : '')
                + `<p class="op-tiempo">⏱ Dura aproximadamente <strong>${duracion(s.min)}</strong> · Valor: <strong>${dinero(precioDe(s, prof))}</strong></p>`;
        }
    }

    boton.addEventListener('click', () => (lista.hidden ? abrir() : cerrar()));
    boton.addEventListener('keydown', ev => {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(ev.key)) { ev.preventDefault(); abrir(); }
    });
    lista.addEventListener('keydown', ev => {
        const n = servicios.length;
        if (ev.key === 'ArrowDown') { ev.preventDefault(); activo = (activo + 1) % n; marcarActivo(); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); activo = (activo - 1 + n) % n; marcarActivo(); }
        else if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); elegir(activo); }
        else if (ev.key === 'Escape') { ev.preventDefault(); cerrar(); }
        else if (ev.key === 'Tab') { cerrar(false); }
        else if (ev.key.toLowerCase() === 'i') { const o = opciones()[activo]; if (o) mostrarDetalle(o, o.querySelector('.op-detalle').hidden); }
    });
    lista.addEventListener('click', ev => {
        const li = ev.target.closest('[role=option]');
        if (!li) return;
        if (ev.target.closest('.op-info')) {   // ⓘ: abre o cierra el detalle sin elegir
            ev.stopPropagation();
            mostrarDetalle(li, li.querySelector('.op-detalle').hidden);
            return;
        }
        elegir(Number(li.dataset.i));
    });
    // Mouse encima 3 segundos = se despliega el detalle y el tiempo que dura
    lista.addEventListener('pointerover', ev => {
        if (ev.pointerType !== 'mouse') return;   // en el celular se usa el botón ⓘ
        const li = ev.target.closest('[role=option]');
        if (!li || li.classList.contains('esperando') || li.classList.contains('con-detalle')) return;
        opciones().forEach(o => o !== li && cancelarEspera(o));
        activo = Number(li.dataset.i);
        marcarActivo();
        li.classList.add('esperando');
        temporizador = setTimeout(() => { li.classList.remove('esperando'); mostrarDetalle(li, true); }, ESPERA_DETALLE);
    });
    lista.addEventListener('pointerout', ev => {
        if (ev.pointerType !== 'mouse') return;
        const li = ev.target.closest('[role=option]');
        if (li && !li.contains(ev.relatedTarget)) { cancelarEspera(li); mostrarDetalle(li, false); }
    });
    document.addEventListener('click', ev => { if (!caja2.contains(ev.target) && !lista.hidden) cerrar(false); });

    // ------------------------------------------------------------------
    // Según el peluquero: servicios que hace y días que trabaja
    // ------------------------------------------------------------------
    const diasEtiquetas = [...form.querySelectorAll('.dia')];
    diasEtiquetas.forEach(l => { l.dataset.mesTexto = l.querySelector('.dia-mes').textContent; });
    function ajustarAlPeluquero() {
        const prof = valor('profesional');
        const s = servicios.find(x => x.id === select.value);
        if (s && prof && !loHace(s, prof)) {
            select.value = '';
            estado.textContent = 'Ese peluquero no hace el servicio que tenías elegido. Elige otro servicio.';
        }
        const trabaja = prof && datos.dias?.[prof] ? datos.dias[prof].map(Number) : null;
        diasEtiquetas.forEach(l => {
            const input = l.querySelector('input');
            const cerrado = trabaja ? !trabaja.includes(Number(l.dataset.dow)) : l.classList.contains('cerrado-salon');
            input.disabled = cerrado;
            l.classList.toggle('cerrado', cerrado);
            l.querySelector('.dia-mes').textContent = cerrado ? (trabaja ? 'no atiende' : 'cerrado') : l.dataset.mesTexto;
            if (cerrado && input.checked) input.checked = false;
        });
        pintarElegido();
    }
    diasEtiquetas.forEach(l => { if (l.classList.contains('cerrado')) l.classList.add('cerrado-salon'); });
    // Texto del mes para días que el salón cierra pero el peluquero sí atiende
    diasEtiquetas.forEach(l => {
        if (l.dataset.mesTexto === 'cerrado') {
            const v = l.querySelector('input').value;
            const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
            l.dataset.mesTexto = meses[Number(v.slice(5, 7)) - 1];
        }
    });

    // ------------------------------------------------------------------
    // Horas libres en tiempo real
    // ------------------------------------------------------------------
    async function cargar() {
        const p = valor('profesional'), serv = valor('servicio'), f = valor('fecha');
        if (!p || !serv || !f) {
            caja.innerHTML = '';
            estado.textContent = 'Elige peluquero, servicio y día para ver las horas libres.';
            return;
        }
        const yo = ++pedido;
        estado.textContent = 'Buscando horas libres…';
        try {
            const url = `/?r=horas&s=${encodeURIComponent(form.dataset.salon)}&p=${p}&serv=${serv}&f=${f}`;
            const r = await fetch(url, { cache: 'no-store' });
            const d = await r.json();
            if (yo !== pedido) return;   // llegó una respuesta vieja
            caja.innerHTML = '';
            if (d.cerrado) {
                estado.textContent = d.mensaje || 'Ese día no se atiende.';
                return;
            }
            const enConf = d.en_confirmacion || [];
            if (!d.horas.length && !enConf.length) {
                estado.textContent = d.mensaje || 'No quedan horas libres ese día. Prueba otro día u otro peluquero.';
                return;
            }
            if (elegida && enConf.includes(elegida)) {
                estado.textContent = `Estamos confirmando las ${elegida} para otro cliente. Elige otra hora.`;
                elegida = '';
            } else if (elegida && !d.horas.includes(elegida)) {
                estado.textContent = `La hora ${elegida} se acaba de ocupar. Elige otra.`;
                elegida = '';
            } else {
                estado.textContent = d.horas.length
                    ? `${d.horas.length} horas libres. Se actualiza solo.`
                    : 'Las horas de este día se están confirmando para otros clientes. Prueba otro día u otro peluquero.';
            }
            const todas = [...d.horas.map(h => [h, true]), ...enConf.map(h => [h, false])]
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
        if (ev.target.name === 'profesional') ajustarAlPeluquero();
        if (ev.target.name === 'servicio') pintarElegido();
        if (['profesional', 'servicio', 'fecha'].includes(ev.target.name)) { elegida = ''; cargar(); }
    });
    form.addEventListener('submit', ev => {
        let falta = null;
        if (!valor('profesional')) falta = 'Elige con quién quieres tu cita.';
        else if (!valor('servicio')) falta = 'Elige un servicio.';
        else if (!valor('hora')) falta = 'Elige una hora para tu cita.';
        if (falta) {
            ev.preventDefault();
            estado.textContent = falta;
            estado.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
    ajustarAlPeluquero();
    cargar();
    setInterval(() => { if (document.visibilityState === 'visible') cargar(); }, 30000);
});
