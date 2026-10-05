<section class="bloque" style="max-width:560px">
    <h1>Tu prueba gratis terminó</h1>
    <p>Tus citas, clientes y cobros están guardados. Elige un plan para seguir usando TuSalón donde lo dejaste.</p>
    <div class="acciones">
        <a class="boton" href="<?= e(url('completa')) ?>">Ver planes</a>
        <a class="boton boton-wa" href="https://wa.me/<?= WHATSAPP_VENTAS ?>?text=<?= rawurlencode('Hola, terminó mi prueba de TuSalón (' . $u['salon'] . ') y quiero contratar un plan.') ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a>
        <a class="boton boton-claro" href="<?= e(url('salir')) ?>">Cerrar sesión</a>
    </div>
</section>
