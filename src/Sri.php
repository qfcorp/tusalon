<?php
declare(strict_types=1);

namespace TuSalon;

use DOMDocument;

/**
 * Lectura de comprobantes electrónicos del SRI que el salón RECIBE (facturas de sus proveedores).
 * TuSalón NO emite facturas: solo lee el XML autorizado, el reporte TXT de "Comprobantes electrónicos
 * recibidos" o descarga el XML con la clave de acceso desde el servicio público del SRI.
 * Adaptado del lector de TuVocalía (app/contabilidad.php), que viene del Sincronizador SRI del ERP de QF.
 */
final class Sri
{
    public const TIPOS = ['factura' => 'Factura', 'liquidacion' => 'Liquidación de compra',
                          'nota_credito' => 'Nota de crédito', 'nota_debito' => 'Nota de débito'];

    public static function nombreTipo(string $cod): string
    {
        return ['01' => 'factura', '03' => 'liquidacion', '04' => 'nota_credito', '05' => 'nota_debito', '06' => 'guia', '07' => 'retencion'][$cod] ?? 'otro';
    }

    /** Clave de acceso de 49 dígitos con su dígito verificador (módulo 11). */
    public static function claveValida(string $clave): bool
    {
        if (!preg_match('/^\d{49}$/', $clave)) return false;
        $suma = 0;
        $factor = 2;
        for ($i = 47; $i >= 0; $i--) {
            $suma += (int) $clave[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $dv = 11 - ($suma % 11);
        if ($dv === 11) $dv = 0;
        if ($dv === 10) $dv = 1;
        return (int) $clave[48] === $dv;
    }

    /** Lo que se lee directo de la clave: fecha, tipo, RUC del emisor, ambiente y número. */
    public static function datosClave(string $clave): array
    {
        return [
            'fecha'      => substr($clave, 4, 4) . '-' . substr($clave, 2, 2) . '-' . substr($clave, 0, 2),
            'tipo'       => self::nombreTipo(substr($clave, 8, 2)),
            'ruc_emisor' => substr($clave, 10, 13),
            'ambiente'   => substr($clave, 23, 1),
            'numero'     => substr($clave, 24, 3) . '-' . substr($clave, 27, 3) . '-' . substr($clave, 30, 9),
        ];
    }

    /** Todas las claves de acceso válidas que haya en un texto (pegado o un TXT). */
    public static function extraerClaves(string $texto): array
    {
        preg_match_all('/(?<!\d)(\d{49})(?!\d)/', $texto, $m);
        return array_values(array_filter(array_unique($m[1]), [self::class, 'claveValida']));
    }

    public static function fecha(string $t): string
    {
        $t = trim($t);
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $t, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m)) return "$m[1]-$m[2]-$m[3]";
        return '';
    }

    public static function num(mixed $v): float
    {
        $s = trim((string) $v);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);   // 1.234,56
        elseif (str_contains($s, ',') && !str_contains($s, '.')) $s = str_replace(',', '.', $s);              // 12,50
        else $s = str_replace(',', '', $s);                                                                // 1,234.56
        return round((float) preg_replace('/[^0-9.\-]/', '', $s), 2);
    }

    /**
     * Lee un comprobante del SRI (el XML "autorizado" <autorizacion><comprobante><![CDATA[…]]> o el comprobante solo).
     * Devuelve ['ok' => true, tipo, clave_acceso, ruc_emisor, razon_social, numero, fecha, id_comprador,
     * subtotal, iva, total, detalle, xml] o ['ok' => false, 'error' => '…'].
     */
    public static function leerComprobante(string $contenido): array
    {
        $contenido = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $contenido));
        if ($contenido === '') return ['ok' => false, 'error' => 'El archivo está vacío.'];
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $contenido)) return ['ok' => false, 'error' => 'No es un comprobante del SRI.'];
        $anterior = libxml_use_internal_errors(true);
        try {
            $original = $contenido;
            $xml = simplexml_load_string($contenido, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if ($xml === false) return ['ok' => false, 'error' => 'No es un XML válido.'];
            $claveAut = '';
            if (in_array($xml->getName(), ['autorizacion', 'RespuestaAutorizacionComprobante'], true) || isset($xml->comprobante)) {
                $aut = $xml->getName() === 'autorizacion' ? $xml : ($xml->xpath('//autorizacion')[0] ?? $xml);
                $claveAut = trim((string) ($aut->numeroAutorizacion ?? ''));
                $interno = trim((string) ($aut->comprobante ?? ''));
                if ($interno === '') return ['ok' => false, 'error' => 'El XML no trae el comprobante.'];
                if (preg_match('/<!DOCTYPE|<!ENTITY/i', $interno)) return ['ok' => false, 'error' => 'No es un comprobante del SRI.'];
                $xml = simplexml_load_string($interno, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
                if ($xml === false) return ['ok' => false, 'error' => 'El comprobante dentro del XML no es válido.'];
            }
            $raiz = $xml->getName();
            $it = $xml->infoTributaria;
            if (!$it || !isset($it->ruc)) return ['ok' => false, 'error' => 'No es un comprobante electrónico del SRI.'];
            $d = [
                'ok' => true, 'tipo' => self::nombreTipo(trim((string) $it->codDoc)),
                'clave_acceso' => (string) preg_replace('/\D/', '', (string) $it->claveAcceso),
                'ruc_emisor'   => (string) preg_replace('/\D/', '', (string) $it->ruc),
                'razon_social' => trim((string) $it->razonSocial) ?: trim((string) $it->nombreComercial),
                'numero'       => str_pad(trim((string) $it->estab), 3, '0', STR_PAD_LEFT) . '-' . str_pad(trim((string) $it->ptoEmi), 3, '0', STR_PAD_LEFT)
                                  . '-' . str_pad(trim((string) $it->secuencial), 9, '0', STR_PAD_LEFT),
                'fecha' => '', 'id_comprador' => '', 'subtotal' => 0.0, 'iva' => 0.0, 'total' => 0.0, 'detalle' => '', 'xml' => $original,
            ];
            if ($d['clave_acceso'] === '' && preg_match('/^\d{49}$/', $claveAut)) $d['clave_acceso'] = $claveAut;
            $sumarIva = function ($lista) use (&$d): void {
                foreach ($lista as $imp) {
                    if (trim((string) $imp->codigo) === '2') $d['iva'] += self::num($imp->valor);   // 2 = IVA
                }
            };
            $inf = match ($raiz) {
                'factura' => $xml->infoFactura, 'liquidacionCompra' => $xml->infoLiquidacionCompra,
                'notaCredito' => $xml->infoNotaCredito, 'notaDebito' => $xml->infoNotaDebito, default => null,
            };
            if ($raiz === 'comprobanteRetencion') return ['ok' => false, 'error' => 'Es un comprobante de retención (no es un gasto): no se importa.'];
            if ($inf === null) return ['ok' => false, 'error' => "Tipo de comprobante no reconocido ($raiz)."];
            $d['fecha'] = self::fecha((string) $inf->fechaEmision);
            $d['id_comprador'] = (string) preg_replace('/[^0-9A-Za-z]/', '', (string) ($inf->identificacionComprador ?? ''));
            $d['subtotal'] = self::num($inf->totalSinImpuestos);
            $d['total'] = self::num(match ($raiz) { 'notaCredito' => $inf->valorModificacion, 'notaDebito' => $inf->valorTotal, default => $inf->importeTotal });
            if ($raiz === 'notaDebito') { if (isset($inf->impuestos->impuesto)) $sumarIva($inf->impuestos->impuesto); }
            elseif (isset($inf->totalConImpuestos->totalImpuesto)) $sumarIva($inf->totalConImpuestos->totalImpuesto);
            $items = [];
            foreach ($xml->xpath('//detalles/detalle') ?: [] as $det) {
                $txt = trim((string) $det->descripcion) ?: trim((string) $det->motivo);
                if ($txt !== '') $items[] = (self::num($det->cantidad) > 1 ? rtrim(rtrim(number_format(self::num($det->cantidad), 2, '.', ''), '0'), '.') . ' × ' : '') . $txt;
            }
            foreach ($xml->xpath('//motivos/motivo') ?: [] as $mo) if (trim((string) $mo->razon) !== '') $items[] = trim((string) $mo->razon);
            $d['detalle'] = mb_substr(implode(' · ', array_slice($items, 0, 6)) . (count($items) > 6 ? ' · y ' . (count($items) - 6) . ' más' : ''), 0, 400);
            $d['iva'] = round($d['iva'], 2);
            if ($d['total'] == 0.0) $d['total'] = round($d['subtotal'] + $d['iva'], 2);
            if ($d['fecha'] === '' && $d['clave_acceso'] !== '') $d['fecha'] = self::datosClave($d['clave_acceso'])['fecha'];
            if ($d['fecha'] === '') return ['ok' => false, 'error' => 'El comprobante no tiene fecha de emisión.'];
            return $d;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /**
     * Filas del reporte TXT "Comprobantes electrónicos recibidos" de SRI en línea (tabulaciones; también ; o ,).
     * Se guía por los títulos de las columnas y, si no los hay, reconoce la clave, el RUC, la fecha y el número por su forma.
     * Devuelve ['filas' => [datos como leerComprobante pero sin xml], 'errores' => [...]].
     */
    public static function leerTxt(string $contenido): array
    {
        $r = ['filas' => [], 'errores' => []];
        $contenido = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        if (!mb_check_encoding($contenido, 'UTF-8')) $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
        $lineas = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $contenido))), fn($x) => $x !== ''));
        $norm = fn(string $c) => strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim(strtr($c, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']))));
        $mapa = [];
        foreach ($lineas as $idx => $linea) {
            $cols = explode("\t", $linea);
            if (count($cols) < 4) $cols = str_getcsv($linea, ';', '"', '\\');
            if (count($cols) < 4) $cols = str_getcsv($linea, ',', '"', '\\');
            $cols = array_map(fn($c) => trim((string) $c, " \"'"), $cols);
            $titulos = array_map(fn($c) => trim($norm($c), '_'), $cols);
            if (!preg_match('/\d{49}/', $linea) && array_intersect(['RUC_EMISOR', 'CLAVE_ACCESO', 'COMPROBANTE'], $titulos)) {
                $mapa = array_flip($titulos);
                continue;
            }
            $val = function (array $nombres) use ($mapa, $cols): string {
                foreach ($nombres as $n) if (isset($mapa[$n]) && ($cols[$mapa[$n]] ?? '') !== '') return $cols[$mapa[$n]];
                return '';
            };
            $clave = (string) preg_replace('/\D/', '', $val(['CLAVE_ACCESO', 'CLAVE_DE_ACCESO']));
            if (strlen($clave) !== 49) { preg_match('/(?<!\d)\d{49}(?!\d)/', $linea, $m); $clave = $m[0] ?? ''; }
            if ($clave === '' || !self::claveValida($clave)) {
                if (preg_match('/\d{10,}/', $linea)) $r['errores'][] = 'Línea ' . ($idx + 1) . ': sin clave de acceso válida.';
                continue;
            }
            $dc = self::datosClave($clave);
            if (!isset(self::TIPOS[$dc['tipo']])) continue;   // retenciones y guías no son gastos
            $ruc = (string) preg_replace('/\D/', '', $val(['RUC_EMISOR', 'RUC'])) ?: $dc['ruc_emisor'];
            $razon = $val(['RAZON_SOCIAL_EMISOR', 'RAZON_SOCIAL', 'NOMBRE_EMISOR']);
            if ($razon === '') {
                foreach ($cols as $c) {
                    if (preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]{3,}/u', $c) && !preg_match('/^(factura|nota|liquid|comprobante|autorizad)/i', $c)) { $razon = $c; break; }
                }
            }
            $total = self::num($val(['IMPORTE_TOTAL', 'VALOR_TOTAL', 'TOTAL']));
            if ($total == 0.0 && !$mapa) {
                $nums = array_values(array_filter($cols, fn($c) => (bool) preg_match('/^-?\d+([.,]\d{1,2})$/', $c)));
                $total = $nums ? self::num(end($nums)) : 0.0;
            }
            $sin = self::num($val(['VALOR_SIN_IMPUESTOS', 'SUBTOTAL', 'BASE_IMPONIBLE']));
            $iva = self::num($val(['IVA', 'VALOR_IVA']));
            $r['filas'][] = [
                'clave_acceso' => $clave, 'tipo' => $dc['tipo'], 'ruc_emisor' => $ruc, 'razon_social' => mb_strtoupper($razon),
                'numero' => $dc['numero'], 'fecha' => self::fecha($val(['FECHA_EMISION', 'FECHA'])) ?: $dc['fecha'],
                'id_comprador' => (string) preg_replace('/\D/', '', $val(['IDENTIFICACION_RECEPTOR', 'RUC_RECEPTOR'])),
                'subtotal' => $sin ?: ($iva > 0 ? round($total - $iva, 2) : $total), 'iva' => $iva, 'total' => $total, 'detalle' => '', 'xml' => '',
            ];
        }
        if (!$r['filas'] && !$r['errores']) {
            $r['errores'][] = 'El archivo no tiene comprobantes. Descarga de SRI en línea el reporte de "Comprobantes electrónicos recibidos" (archivo .txt).';
        }
        return $r;
    }

    /**
     * Descarga del servicio público del SRI el comprobante autorizado de una clave de acceso.
     * Devuelve ['ok' => true, 'xml' => '<autorizacion>…'] o ['ok' => false, 'error' => '…'].
     * SRI_WS_URL (variable de entorno) solo se usa en las pruebas.
     */
    public static function descargar(string $clave): array
    {
        if (!self::claveValida($clave)) return ['ok' => false, 'error' => 'Clave de acceso no válida.'];
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'El servidor no tiene cURL (instala php-curl).'];
        $url = getenv('SRI_WS_URL') ?: (substr($clave, 23, 1) === '1'
            ? 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline'
            : 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline');
        $soap = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ec="http://ec.gob.sri.ws.autorizacion">'
              . '<soapenv:Header/><soapenv:Body><ec:autorizacionComprobante><claveAccesoComprobante>' . $clave . '</claveAccesoComprobante>'
              . '</ec:autorizacionComprobante></soapenv:Body></soapenv:Envelope>';
        $resp = false;
        $cod = 0;
        $err = '';
        for ($intento = 1; $intento <= 2; $intento++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $soap, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: text/xml;charset=UTF-8', 'SOAPAction: ""'], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25]);
            $resp = curl_exec($ch);
            $cod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            if ($resp !== false && $cod === 200) break;
            if ($intento === 1) usleep(500000);
        }
        if ($resp === false || $cod === 0) return ['ok' => false, 'error' => 'Sin conexión con el SRI' . ($err !== '' ? " ($err)" : '') . '. Intenta más tarde.'];
        if ($cod !== 200) return ['ok' => false, 'error' => "El SRI respondió con código $cod. Intenta más tarde."];
        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $cargo = $dom->loadXML((string) $resp, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$cargo) return ['ok' => false, 'error' => 'La respuesta del SRI no es válida.'];
        $auts = $dom->getElementsByTagName('autorizacion');
        if ($auts->length === 0) return ['ok' => false, 'error' => 'El SRI no tiene registrado este comprobante.'];
        $el = null;
        foreach ($auts as $a) {
            $est = trim((string) ($a->getElementsByTagName('estado')->item(0)?->textContent ?? ''));
            if ($est === 'AUTORIZADO') { $el = $a; break; }
            $el ??= $a;
        }
        $estado = trim((string) ($el->getElementsByTagName('estado')->item(0)?->textContent ?? ''));
        if ($estado !== 'AUTORIZADO') return ['ok' => false, 'error' => 'El comprobante está ' . ($estado ?: 'sin estado') . ' en el SRI.'];
        $comp = trim((string) ($el->getElementsByTagName('comprobante')->item(0)?->textContent ?? ''));
        if ($comp === '') return ['ok' => false, 'error' => 'El SRI no envió el comprobante.'];
        $num = trim((string) ($el->getElementsByTagName('numeroAutorizacion')->item(0)?->textContent ?? ''));
        $fec = trim((string) ($el->getElementsByTagName('fechaAutorizacion')->item(0)?->textContent ?? ''));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>' . htmlspecialchars($num, ENT_XML1)
             . '</numeroAutorizacion><fechaAutorizacion>' . htmlspecialchars($fec, ENT_XML1) . '</fechaAutorizacion><comprobante><![CDATA['
             . str_replace(']]>', ']]]]><![CDATA[>', $comp) . ']]></comprobante></autorizacion>';
        return ['ok' => true, 'xml' => $xml];
    }
}
