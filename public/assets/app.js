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
        const recalcular = () => {
            let suma = 0;
            formCobro.querySelectorAll('[data-servicio]').forEach(fila => {
                const marcado = fila.querySelector('input[type=checkbox]').checked;
                const precio = parseFloat(fila.querySelector('input[type=number]').value || '0');
                if (marcado) suma += precio;
            });
            const propina = parseFloat(formCobro.querySelector('[name=propina]').value || '0');
            total.textContent = '$' + (suma + propina).toFixed(2).replace('.', ',');
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
});
