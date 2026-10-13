// Cobro de planes dentro de la app de Android (Google Play Billing, por la Digital Goods API).
(() => {
    const caja = document.getElementById('pago-play');
    if (!caja) return;
    const estado = document.getElementById('play-estado');
    const botones = [...caja.querySelectorAll('.boton-play')];
    const ids = botones.map(b => b.dataset.producto);
    const METODO = 'https://play.google.com/billing';
    const avisar = (texto, tipo = '') => { estado.textContent = texto; estado.className = 'aviso' + (tipo ? ' aviso-' + tipo : ''); };

    const registrar = (producto, token) => fetch('/?r=play_compra', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ producto, token, csrf: caja.dataset.csrf }),
    }).then(r => r.json());

    (async () => {
        if (!('getDigitalGoodsService' in window)) {
            avisar('Para pagar con Google Play, abre TuSalón desde la app instalada desde Play Store.');
            return;
        }
        let servicio;
        try {
            servicio = await window.getDigitalGoodsService(METODO);
        } catch (e) {
            avisar('Google Play no está disponible en este celular. Actualiza Play Store e intenta de nuevo.', 'error');
            return;
        }
        // Compras que el sistema todavía no tenga registradas (por ejemplo, si se cortó el internet al pagar)
        try {
            for (const p of await servicio.listPurchases()) {
                if (ids.includes(p.itemId)) await registrar(p.itemId, p.purchaseToken);
            }
        } catch (e) { /* no es grave */ }

        try {
            const detalles = await servicio.getDetails(ids);
            for (const d of detalles) {
                const precio = new Intl.NumberFormat('es-EC', { style: 'currency', currency: d.price.currency }).format(Number(d.price.value));
                const celda = caja.querySelector(`[data-precio="${d.itemId}"]`);
                if (celda) celda.textContent = precio;
                const b = botones.find(x => x.dataset.producto === d.itemId);
                if (b) b.disabled = false;
            }
            avisar(detalles.length ? 'Elige tu plan. El pago lo hace Google Play.' : 'Los planes todavía no están disponibles en Google Play.', detalles.length ? 'ok' : 'error');
        } catch (e) {
            avisar('No se pudieron cargar los precios. Revisa tu internet e intenta de nuevo.', 'error');
        }

        botones.forEach(b => b.addEventListener('click', async () => {
            const producto = b.dataset.producto;
            let respuesta;
            try {
                const pedido = new PaymentRequest([{ supportedMethods: METODO, data: { sku: producto } }],
                    { total: { label: 'Total', amount: { currency: 'USD', value: '0' } } });
                respuesta = await pedido.show();
            } catch (e) {
                avisar('No se completó el pago.', 'error');
                return;
            }
            avisar('Confirmando tu pago con Google…');
            try {
                const r = await registrar(producto, respuesta.details.purchaseToken);
                await respuesta.complete(r.ok ? 'success' : 'fail');
                if (r.ok) {
                    avisar(`¡Listo! Tu plan quedó activo hasta el ${r.hasta}.`, 'ok');
                    setTimeout(() => { location.href = caja.dataset.volver; }, 2500);
                } else {
                    avisar(r.error || 'No se pudo confirmar el pago. Escríbenos.', 'error');
                }
            } catch (e) {
                await respuesta.complete('fail');
                avisar('Se cortó la conexión. Abre esta página otra vez: tu pago se registra solo.', 'error');
            }
        }));
    })();
})();
