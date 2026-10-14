<?php
/**
 * Solo para pruebas: imita a Telegram y a Payphone con "php -S 127.0.0.1:<puerto> tests/simulador.php".
 *  - /bot<token>/<metodo>  (Telegram): guarda {"metodo","datos"} en TG_MOCK_LOG y responde ok
 *  - /sri (servicio de autorización del SRI): factura autorizada armada con la clave (SRI_MOCK_LOG anota las claves pedidas)
 *  - lo demás (Payphone): guarda "Authorization URI cuerpo" en PP_MOCK_DIR/llamadas.log y responde PP_MOCK_DIR/respuesta.json
 */
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cuerpo = (string) file_get_contents('php://input');
header('Content-Type: application/json');
if (str_starts_with($ruta, '/bot')) {
    $log = (string) getenv('TG_MOCK_LOG');
    if ($log !== '') {
        $linea = json_encode(['metodo' => basename($ruta), 'datos' => json_decode($cuerpo, true) ?? []], JSON_UNESCAPED_UNICODE);
        file_put_contents($log, $linea . "\n", FILE_APPEND | LOCK_EX);
    }
    echo json_encode(['ok' => true, 'result' => ['message_id' => 1]]);
    return;
}
if (str_starts_with($ruta, '/sri')) {
    // Servicio de autorización del SRI: devuelve una factura autorizada armada con los datos de la clave.
    // Secuencial 999999999 = el SRI no la tiene; 999999998 = nota de crédito (codDoc 04 ya viene en la clave).
    header('Content-Type: text/xml; charset=utf-8');
    preg_match('/<claveAccesoComprobante>(\d{49})</', $cuerpo, $m);
    $clave = $m[1] ?? '';
    $log = (string) getenv('SRI_MOCK_LOG');
    if ($log !== '') file_put_contents($log, $clave . "\n", FILE_APPEND | LOCK_EX);
    $sec = substr($clave, 30, 9);
    if ($clave === '' || $sec === '999999999') {
        echo '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante><claveAccesoConsultada>' . $clave . '</claveAccesoConsultada><numeroComprobantes>0</numeroComprobantes><autorizaciones/></RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
        return;
    }
    $fecha = substr($clave, 0, 2) . '/' . substr($clave, 2, 2) . '/' . substr($clave, 4, 4);
    $cod = substr($clave, 8, 2);
    $ruc = substr($clave, 10, 13);
    $it = "<infoTributaria><ambiente>2</ambiente><tipoEmision>1</tipoEmision><razonSocial>PROVEEDOR SIMULADO $ruc</razonSocial><ruc>$ruc</ruc>"
        . "<claveAcceso>$clave</claveAcceso><codDoc>$cod</codDoc><estab>" . substr($clave, 24, 3) . '</estab><ptoEmi>' . substr($clave, 27, 3) . "</ptoEmi><secuencial>$sec</secuencial></infoTributaria>";
    $imp = '<totalConImpuestos><totalImpuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><baseImponible>10.00</baseImponible><valor>1.50</valor></totalImpuesto></totalConImpuestos>';
    $comp = $cod === '04'
        ? "<notaCredito id=\"comprobante\">$it<infoNotaCredito><fechaEmision>$fecha</fechaEmision><identificacionComprador>1790000000001</identificacionComprador><numDocModificado>001-001-000000001</numDocModificado><totalSinImpuestos>10.00</totalSinImpuestos><valorModificacion>11.50</valorModificacion>$imp</infoNotaCredito><motivos><motivo><razon>Devolucion de tinte</razon><valor>10.00</valor></motivo></motivos></notaCredito>"
        : "<factura id=\"comprobante\">$it<infoFactura><fechaEmision>$fecha</fechaEmision><identificacionComprador>1790000000001</identificacionComprador><totalSinImpuestos>10.00</totalSinImpuestos>$imp<importeTotal>11.50</importeTotal></infoFactura><detalles><detalle><descripcion>Tinte rubio 7.1</descripcion><cantidad>2</cantidad></detalle></detalles></factura>";
    echo '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante><claveAccesoConsultada>' . $clave
        . '</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>' . $clave
        . '</numeroAutorizacion><fechaAutorizacion>2026-10-14T10:00:00-05:00</fechaAutorizacion><ambiente>PRODUCCIÓN</ambiente><comprobante><![CDATA[<?xml version="1.0" encoding="UTF-8"?>'
        . $comp . ']]></comprobante></autorizacion></autorizaciones></RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
    return;
}
$dir = (string) getenv('PP_MOCK_DIR');
if ($dir !== '') {
    file_put_contents("$dir/llamadas.log", ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . ' ' . $_SERVER['REQUEST_URI'] . ' ' . $cuerpo . "\n", FILE_APPEND | LOCK_EX);
}
echo ($dir !== '' && is_file("$dir/respuesta.json")) ? file_get_contents("$dir/respuesta.json") : '{}';
