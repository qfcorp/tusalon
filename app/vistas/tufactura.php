<?php /** @var string $html  pantalla de TukanFactura (ya escapada) */ ?>
<div class="tk-titulo-pagina cabecera"><div><h1>🧾 Factura electrónica</h1><p>Tus facturas electrónicas del SRI con TuFactura</p></div></div>
<?php // «Quitar la conexión» pregunta antes de enviarse: sin el botón «Procesando…» del kit (si se cancela, el botón sigue igual)
echo str_replace(' onsubmit="return confirm(', ' data-sin-ocupado onsubmit="return confirm(', $html); ?>
