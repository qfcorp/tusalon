<?php
use TuSalon\{Automaticas, FacturasRecibidas, Liquidacion, Sri};

/** Gastos del salón con las facturas que RECIBE de sus proveedores (importadas del SRI). TuSalón no emite facturas. */
if (!requiere_completa($u, 'Gastos y facturas', 'Sube las facturas que te dan tus proveedores (tintes, shampoo, luz, agua, arriendo del local) desde el SRI y mira tus gastos y tu ganancia real cada mes. TuSalón no hace facturas: solo lee las que recibes.')) return;
$sid = (int) $u['salon_id'];
$pdo = db();
$fr = new FacturasRecibidas($pdo);
$mes = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) || $mes > date('Y-m')) $mes = date('Y-m');
$categoria = (string) ($_GET['cat'] ?? '');
if (!isset(FacturasRecibidas::CATEGORIAS[$categoria])) $categoria = '';
$volver = ['mes' => $mes] + ($categoria !== '' ? ['cat' => $categoria] : []);

/** Archivos de un campo múltiple, incluidos los XML que vengan dentro de un .zip: [[nombre, contenido|null], ...]. */
$archivosSubidos = function (string $campo): array {
    $f = $_FILES[$campo] ?? null;
    if (!$f) return [];
    $n = is_array($f['name']) ? count($f['name']) : 1;
    $res = [];
    for ($i = 0; $i < $n; $i++) {
        $nom = (string) (is_array($f['name']) ? $f['name'][$i] : $f['name']);
        $tmp = (string) (is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name']);
        $err = (int) (is_array($f['error']) ? $f['error'][$i] : $f['error']);
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $res[] = [$nom, null]; continue; }
        if (str_ends_with(strtolower($nom), '.zip')) {
            if (!class_exists('ZipArchive')) { $res[] = [$nom . ' (el servidor no abre .zip: sube los XML sueltos)', null]; continue; }
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true) {
                for ($k = 0; $k < min($zip->numFiles, 500); $k++) {
                    $zn = (string) $zip->getNameIndex($k);
                    if (str_ends_with(strtolower($zn), '.xml') && ($zip->statIndex($k)['size'] ?? 0) < 2 * 1024 * 1024) {
                        $res[] = [basename($zn), (string) $zip->getFromIndex($k)];
                    }
                }
                $zip->close();
            } else {
                $res[] = [$nom, null];
            }
            continue;
        }
        $res[] = [$nom, filesize($tmp) < 2 * 1024 * 1024 ? (string) file_get_contents($tmp) : null];
    }
    return $res;
};

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if (in_array($accion, ['xml', 'txt', 'claves', 'completar'], true) && !function_exists('simplexml_load_string')) {
            throw new RuntimeException('Al servidor le falta el programa para leer XML (php-xml). Avísale a Tukán.');
        }
        $r = null;
        switch ($accion) {
            case 'xml':
                $archivos = $archivosSubidos('xml');
                if (!$archivos) throw new RuntimeException('Elige uno o varios archivos XML (o un .zip con los XML).');
                $r = $fr->importarXmls($sid, $archivos, (int) $u['id']);
                break;
            case 'txt':
                $t = $archivosSubidos('txt');
                if (!$t || $t[0][1] === null) throw new RuntimeException('Elige el archivo .txt que bajaste del SRI.');
                $r = $fr->importarTxt($sid, $t[0][1], (int) $u['id']);
                if ($r['nueva'] > 0) $r['nota'] = 'Toca "Completar con el SRI" para traer el detalle de cada factura.';
                break;
            case 'claves':
                $claves = Sri::extraerClaves((string) ($_POST['claves'] ?? ''));
                if (!$claves) throw new RuntimeException('No encontré ninguna clave de acceso válida (son 49 números).');
                $r = $fr->importarClaves($sid, $claves, (int) $u['id']);
                break;
            case 'completar':
                $r = $fr->completarDesdeSri($sid, (int) $u['id']);
                break;
            case 'categoria':
                $fr->cambiarCategoria($sid, (int) ($_POST['id'] ?? 0), (string) ($_POST['categoria'] ?? ''), true);
                aviso('Categoría guardada. Las próximas facturas de ese proveedor se clasifican solas.');
                break;
            case 'borrar':
                $fr->borrar($sid, (int) ($_POST['id'] ?? 0));
                aviso('Factura quitada de tus gastos.');
                break;
            case 'ruc':
                $ruc = preg_replace('/\D/', '', (string) ($_POST['ruc'] ?? ''));
                if ($ruc !== '' && !in_array(strlen($ruc), [10, 13], true)) throw new RuntimeException('El RUC tiene 13 números y la cédula 10.');
                $pdo->prepare('UPDATE salones SET ruc = ? WHERE id = ?')->execute([$ruc ?: null, $sid]);
                aviso($ruc ? 'Listo. Te avisaremos si una factura viene a nombre de otra persona.' : 'RUC borrado.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
        if ($r !== null) {
            aviso(FacturasRecibidas::textoResultado($r) . (isset($r['nota']) ? ' ' . $r['nota'] : ''), $r['nueva'] || $r['completada'] ? 'ok' : 'error');
            if ($r['ajenas']) aviso($r['ajenas'] . ($r['ajenas'] === 1 ? ' factura viene' : ' facturas vienen') . ' a nombre de otra persona (no de tu RUC). Revísalas y quítalas si no son del salón.', 'error');
            foreach (array_slice($r['errores'], 0, 8) as $err) aviso($err, 'error');
            if (count($r['errores']) > 8) aviso('Y ' . (count($r['errores']) - 8) . ' errores más.', 'error');
        }
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) throw $e;
        aviso($e->getMessage(), 'error');
    }
    redirigir('gastos', $volver);
}

$desde = "$mes-01";
$hasta = date('Y-m-t', strtotime($desde));
$resumen = (new Liquidacion($pdo))->resumenDueno($sid, $desde, $hasta);
$facturas = $fr->lista($sid, $desde, $hasta, $categoria ?: null);
$porCompletar = $fr->porCompletar($sid);
$st = $pdo->prepare('SELECT ruc FROM salones WHERE id = ?');
$st->execute([$sid]);
$rucSalon = (string) $st->fetchColumn();
$meses = [];
for ($i = 0; $i < 12; $i++) {
    $m = date('Y-m', strtotime("first day of -$i month"));
    $meses[$m] = ucfirst(Automaticas::nombreMes($m));
}
vista('gastos', compact('resumen', 'facturas', 'porCompletar', 'rucSalon', 'mes', 'meses', 'categoria'), 'Gastos y facturas · TuSalón');
