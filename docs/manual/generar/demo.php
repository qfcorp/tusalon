<?php
declare(strict_types=1);
/**
 * Salón de ejemplo para las capturas del manual (lo corre docs/manual/generar/generar.sh en una base *manual* o *prueba*).
 *   TUSALON_DB_NAME=tusalon_manual_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php docs/manual/generar/demo.php <carpeta-de-imagenes>
 *
 * Crea, como lo haría Tukán y luego el dueño desde las pantallas:
 *   - «Salón Demo Tukán» (plan Completa, pagado): dueña, empleado, porcentaje y alquiler de puesto, recepción, servicios con
 *     sus precios (son del salón, no del software), clientes, un mes de cobros, citas de hoy y de mañana, solicitudes en línea
 *     (una con anticipo por revisar), inventario, paquetes, tarjetas de regalo, puntos, ficha técnica y calificaciones.
 *   - «Barbería Solo Base» (plan Básica, pagado): para Mi plan («Precio por cotización» y «Pedir cotización») y «No está en tu plan».
 *   - «Peluquería en Demo» (demo con todos los módulos): para «Lo quiero · Pedir cotización».
 *   - El salón de ejemplo tiene además el módulo «Asistente IA» y una pregunta frecuente de la Ayuda escrita a mano
 *     (las capturas se toman sin /etc/tukan-ia.env: la IA queda apagada y la Ayuda responde con esa pregunta).
 * Imprime al final las variables para capturas.py (clave=valor).
 */
define('RAIZ', dirname(__DIR__, 3));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'TuSalon\\') && is_file($f = RAIZ . '/src/' . substr($c, 8) . '.php')) require $f;
});
use TuSalon\{Agenda, Altas, Anticipos, Calificaciones, Cobro, Db, FichaTecnica, Fidelidad, Inventario, Liquidacion, Planes, Profesionales};

$imagenes = $argv[1] ?? '';
const CLAVE = 'SalonDemo2026';
$db = Db::conectar();
$planes = new Planes($db);
$agenda = new Agenda($db);
$fid = new Fidelidad($db);
$inv = new Inventario($db);
$cobro = new Cobro($db);
$hoy = new DateTimeImmutable('today');
$ahora = new DateTimeImmutable();
$q = function (string $sql, array $p = []) use ($db) { $st = $db->prepare($sql); $st->execute($p); return $st; };

// ---------------------------------------------------------------- 1. El salón y su dueña
$a = (new Altas($db))->crearSalonConDueno('Salón Demo Tukán', 'Daniela Ramos', '0991112233', 'demo@tusalon.tukanec.com', CLAVE, 'completa', 'super');
$sid = $a['salon_id'];
$q("UPDATE salones SET estado = 'activo', activo_hasta = CURRENT_DATE + 45, acepta_reservas = 'dueno', avisar_a = 'ambos',
           google_resenas_url = 'https://g.page/r/salon-demo-tukan/review', semanas_sin_volver = 6, intervalo_reservas = 15,
           anticipacion_minutos = 30 WHERE id = ?", [$sid]);
// Abre de lunes a sábado de 9:00 a 21:00 y el domingo de 9:00 a 14:00
$dias = [];
for ($d = 0; $d <= 6; $d++) $dias[$d] = $d === 0 ? ['abre' => '09:00', 'cierra' => '14:00'] : ['abre' => '09:00', 'cierra' => '21:00'];
$agenda->guardarHorario($sid, $dias);
$dueno = (int) $q("SELECT id FROM profesionales WHERE salon_id = ? AND tipo = 'dueno'", [$sid])->fetchColumn();
$q("UPDATE reglas_pago SET vigente_desde = CURRENT_DATE - 120 WHERE profesional_id = ?", [$dueno]);
$q("UPDATE profesionales SET fecha_ingreso = CURRENT_DATE - 120 WHERE id = ?", [$dueno]);
$q("UPDATE salones SET creado_en = now() - interval '120 days' WHERE id = ?", [$sid]);

// ---------------------------------------------------------------- 2. Equipo: los 4 tipos de peluquero
$prof = new Profesionales($db, $planes);
$haceUnMes = $hoy->modify('-120 days')->format('Y-m-d');
$carlos = $prof->agregar($sid, 'Carlos Mena', 'empleado', ['sueldo_mensual' => 470, 'comision_servicio_pct' => 30, 'comision_producto_pct' => 10, 'afiliado_iess' => true], $haceUnMes);
$ana = $prof->agregar($sid, 'Ana Vera', 'porcentaje', ['pct_profesional' => 50, 'quien_cobra' => 'local'], $haceUnMes);
$jorge = $prof->agregar($sid, 'Jorge Paz', 'alquiler', ['monto_arriendo' => 60, 'frecuencia_arriendo' => 'semanal'], $haceUnMes);
foreach ([[$carlos, '0987001122'], [$ana, '0987003344'], [$jorge, '0987005566']] as [$pid, $tel]) $q('UPDATE profesionales SET telefono = ? WHERE id = ?', [$tel, $pid]);
// Accesos al portal (Equipo → «Darle acceso a su portal») y la cuenta de recepción (Más → Recepción)
$ins = $db->prepare("INSERT INTO usuarios (salon_id, profesional_id, nombre, email, password_hash, rol) VALUES (?,?,?,?,?,?)");
$ins->execute([$sid, $carlos, 'Carlos Mena', 'carlos@tusalon.tukanec.com', password_hash(CLAVE, PASSWORD_DEFAULT), 'profesional']);
$ins->execute([$sid, $jorge, 'Jorge Paz', 'jorge@tusalon.tukanec.com', password_hash(CLAVE, PASSWORD_DEFAULT), 'profesional']);
$ins->execute([$sid, null, 'Paola Cedeño', 'recepcion@tusalon.tukanec.com', password_hash(CLAVE, PASSWORD_DEFAULT), 'recepcion']);
// Administradores (Más → Administradores): mismo nivel que la dueña, no atienden ni cuentan en el límite de personas
$ins->execute([$sid, null, 'Sofía Andrade', 'sofia@tusalon.tukanec.com', password_hash(CLAVE, PASSWORD_DEFAULT), 'admin']);
$ins->execute([$sid, null, 'Diego Ruiz', 'diego@tusalon.tukanec.com', password_hash(CLAVE, PASSWORD_DEFAULT), 'admin']);
$q("UPDATE usuarios SET activo = false WHERE email = 'diego@tusalon.tukanec.com'");
// Carlos: lunes libre y almuerzo de 13:00 a 14:00; Ana: vacaciones la próxima semana
$hc = [];
for ($d = 0; $d <= 6; $d++) $hc[$d] = in_array($d, [0, 1], true) ? null : ['abre' => '09:00', 'cierra' => '21:00', 'almuerzo_desde' => '13:00', 'almuerzo_hasta' => '14:00'];
$agenda->guardarHorarioProfesional($sid, $carlos, $hc, false);
$lunes = $hoy->modify('next monday');
$agenda->agregarBloqueo($sid, $ana, $lunes->modify('+7 days')->format('Y-m-d'), $lunes->modify('+12 days')->format('Y-m-d'), 'Vacaciones');

// ---------------------------------------------------------------- 3. Servicios y precios del salón
$q('DELETE FROM servicios WHERE salon_id = ?', [$sid]);
$serv = [];
$insS = $db->prepare('INSERT INTO servicios (salon_id, nombre, precio, pago_profesional, duracion_minutos, descripcion) VALUES (?,?,?,?,?,?) RETURNING id');
foreach ([
    'corte'   => ['Corte de caballero', 7, 3, 30, 'Lavado, corte con máquina y tijera, y peinado.'],
    'barba'   => ['Barba', 5, 2, 20, 'Perfilado de barba con navaja, toalla caliente y aceite.'],
    'combo'   => ['Corte y barba', 11, 4.5, 45, 'Corte de caballero más perfilado de barba.'],
    'dama'    => ['Corte de dama', 12, null, 45, 'Lavado, corte y secado con cepillo.'],
    'tinte'   => ['Tinte completo', 30, null, 90, 'Color de raíz a puntas con la marca que elijas. Incluye lavado y secado.'],
    'raiz'    => ['Retoque de raíz', 20, null, 60, 'Cubre las canas o el crecimiento de la raíz.'],
    'keratina'=> ['Keratina', 40, null, 120, 'Alisado con keratina para un cabello liso y sin frizz por varias semanas.'],
    'peinado' => ['Peinado para evento', 15, null, 40, 'Ondas, recogido o planchado para tu evento.'],
] as $k => [$n, $p, $pago, $min, $desc]) {
    $insS->execute([$sid, $n, $p, $pago, $min, $desc]);
    $serv[$k] = (int) $insS->fetchColumn();
}
// La keratina solo la hace Ana, y ella la cobra $45; Jorge no hace color
$agenda->guardarServicioProfesionales($sid, $serv['keratina'], [$ana => 45]);
$agenda->guardarServicioProfesionales($sid, $serv['tinte'], [$dueno => null, $ana => null, $carlos => null]);
$agenda->guardarServicioProfesionales($sid, $serv['raiz'], [$dueno => null, $ana => null, $carlos => null]);
$precioDe = fn(string $k) => (float) $q('SELECT precio FROM servicios WHERE id = ?', [$serv[$k]])->fetchColumn();

// ---------------------------------------------------------------- 4. Módulos que se configuran
$fid->guardarConfigPuntos($sid, true, 1, 0.05, 100);
(new Anticipos($db))->guardarConfig($sid, ['anticipo_modo' => 'nuevos', 'anticipo_tipo' => 'porcentaje', 'anticipo_valor' => 30,
    'anticipo_banco' => "Banco del Pacífico\nCuenta de ahorros 1050123456\nA nombre de: Daniela Ramos\nCédula: 1712345678", 'anticipo_deuna' => '0991112233']);
$prod = [];
foreach ([
    'shampoo' => ['Shampoo keratina 500 ml', 'Salerm', 1, 14, 8, 9, 3],
    'cera'    => ['Cera mate', 'Reuzel', 1, 12, 6.5, 18, 3],
    'aceite'  => ['Aceite para barba', 'Tukán Barber', 1, 9, 4, 2, 3],
    'tinte71' => ['Tinte Igora 7.1', 'Schwarzkopf', 0, 0, 6.2, 12, 4],
    'oxid'    => ['Oxidante 20 vol 1 L', 'Igora', 0, 0, 7, 1, 2],
] as $k => [$n, $marca, $venta, $precio, $costo, $stock, $min]) {
    $prod[$k] = $inv->guardar($sid, null, ['nombre' => $n, 'marca' => $marca, 'para_venta' => $venta, 'precio' => $precio, 'costo' => $costo,
                                            'stock' => $stock, 'stock_minimo' => $min]);
}
$inv->entrada($sid, $prod['shampoo'], 6, 8, 'Factura 001-002-000123');
$inv->uso($sid, $prod['tinte71'], 2, 'Tinte de María José');
$paqCortes = $fid->guardarPaquete($sid, null, ['nombre' => '4 cortes de caballero', 'servicio_id' => $serv['corte'], 'sesiones' => 4, 'precio' => 24, 'vigencia_dias' => 90]);
$paqTinte = $fid->guardarPaquete($sid, null, ['nombre' => '3 tintes completos', 'servicio_id' => $serv['tinte'], 'sesiones' => 3, 'precio' => 80, 'vigencia_dias' => 180]);

// ---------------------------------------------------------------- 5. Clientes
$cli = [];
$insC = $db->prepare('INSERT INTO clientes (salon_id, nombre, telefono, cumple_mes, cumple_dia, alergias, notas, email, creado_en) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
$mesHoy = (int) $hoy->format('n'); $diaHoy = (int) $hoy->format('j');
foreach ([
    'maria'   => ['María José Andrade', '0991234567', 3, 14, 'Amoniaco (usar tinte sin amoniaco)', 'Le gusta el rubio ceniza.', 'mariajose@correo.ec'],
    'andrea'  => ['Andrea Salazar', '0992345678', $mesHoy, $diaHoy, null, null, null],
    'pedro'   => ['Pedro Ortiz', '0993456789', 7, 2, null, 'Degradado bajo, tijera arriba.', null],
    'luis'    => ['Luis Cevallos', '0994567890', null, null, null, null, null],
    'sofia'   => ['Sofía Paredes', '0995678901', 11, 30, null, null, null],
    'diego'   => ['Diego Montalvo', '0996789012', null, null, null, null, null],
    'carla'   => ['Carla Benítez', '0997890123', 5, 9, null, null, null],
    'mateo'   => ['Mateo Villacís', '0998901234', null, null, null, null, null],
    'gabriela'=> ['Gabriela Ruiz', '0999012345', 1, 20, null, null, null],
    'fernando'=> ['Fernando Lara', '0981234567', null, null, null, null, null],
    'valeria' => ['Valeria Chávez', '0982345678', 8, 15, null, null, null],
    'javier'  => ['Javier Suárez', '0983456789', null, null, null, null, null],
    'paula'   => ['Paula Jaramillo', '0984567890', null, null, null, null, null],
    'ricardo' => ['Ricardo Espinoza', '0985678901', null, null, null, null, null],
] as $k => [$n, $t, $mes, $dia, $alergia, $nota, $mail]) {
    $insC->execute([$sid, $n, $t, $mes, $dia, $alergia, $nota, $mail, $hoy->modify('-80 days')->format('Y-m-d H:i')]);
    $cli[$k] = (int) $insC->fetchColumn();
}
// Andrea tiene cuenta (reserva con su correo y ve su historial)
$q("UPDATE clientes SET email = 'andrea@correo.ec', password_hash = ?, acepta_fotos = true, cuenta_creada_en = now() - interval '60 days' WHERE id = ?",
   [password_hash(CLAVE, PASSWORD_DEFAULT), $cli['andrea']]);
// Clientes privados de quien alquila (Jorge)
$insP = $db->prepare('INSERT INTO clientes (salon_id, profesional_privado_id, nombre, telefono, notas) VALUES (?,?,?,?,?)');
$insP->execute([$sid, $jorge, 'Bryan Molina', '0986001100', 'Cliente de Jorge desde su barbería anterior.']);
$insP->execute([$sid, $jorge, 'Kevin Zambrano', '0986002200', null]);

// ---------------------------------------------------------------- 6. Un mes de citas cobradas (para la caja, las cuentas y el reporte)
$quien = [$dueno, $carlos, $ana, $jorge];
$cobrar = function (int $citaId, string $cuando, string $metodo = 'efectivo', float $propina = 0, array $productos = [], array $paquetes = []) use ($agenda, $cobro, $sid) {
    $c = $agenda->cita($sid, $citaId);
    $serv = [];
    foreach ($c['lista_servicios'] as $s) $serv[(int) $s['id']] = (float) $s['precio'];
    $p = (int) $c['profesional_id'];
    $porQR = (int) $GLOBALS['db']->query("SELECT CASE WHEN tipo = 'alquiler' THEN 1 ELSE 0 END FROM profesionales WHERE id = $p")->fetchColumn();
    return $cobro->registrar(['salon_id' => $sid, 'profesional_id' => $p, 'cita' => $c, 'servicios' => $serv, 'productos' => $productos,
        'paquetes' => $paquetes, 'metodo_pago' => $metodo, 'cobrado_por' => $porQR ? 'profesional' : 'local', 'propina' => $propina, 'fecha' => $cuando]);
};
$GLOBALS['db'] = $db;
$mezcla = [
    [$dueno, 'corte'], [$carlos, 'combo'], [$ana, 'dama'], [$jorge, 'corte'], [$carlos, 'corte'], [$ana, 'tinte'],
    [$dueno, 'barba'], [$jorge, 'combo'], [$ana, 'raiz'], [$carlos, 'barba'], [$dueno, 'peinado'], [$ana, 'keratina'],
];
$clientesMes = ['pedro', 'luis', 'sofia', 'diego', 'carla', 'mateo', 'gabriela', 'fernando', 'valeria', 'andrea', 'maria'];
$metodos = ['efectivo', 'transferencia', 'deuna', 'efectivo', 'tarjeta', 'efectivo', 'deuna'];
$n = 0;
$calif = new Calificaciones($db);
for ($dd = 30; $dd >= 1; $dd--) {
    $dia = $hoy->modify("-$dd days");
    if ((int) $dia->format('w') === 0) continue;
    $porDia = 3 + ($dd % 3);
    for ($i = 0; $i < $porDia; $i++) {
        [$p, $sk] = $mezcla[($n + $i) % count($mezcla)];
        if ($sk === 'keratina') $p = $ana;
        if (in_array($sk, ['tinte', 'raiz'], true) && $p === $jorge) $p = $ana;
        if ($p === $carlos && (int) $dia->format('w') === 1) $p = $dueno;
        if ($p === $ana && $dia >= $lunes->modify('+7 days')) $p = $dueno;
        $hora = 10 + $i * 2 + ($dd % 2);
        $inicio = $dia->format('Y-m-d') . sprintf(' %02d:00', $hora);
        $ck = $clientesMes[($n + $i) % count($clientesMes)];
        $cid = $agenda->crearCita($sid, $p, $cli[$ck], $inicio, [$serv[$sk]]);
        $prods = ($n + $i) % 9 === 0 ? [$prod['cera'] => 1] : [];
        $r = $cobrar($cid, $dia->format('Y-m-d') . sprintf(' %02d:50', $hora), $metodos[($n + $i) % count($metodos)], ($n + $i) % 4 === 0 ? 1 : 0, $prods);
        if (($n + $i) % 3 === 0) {
            $c = $agenda->cita($sid, $cid);
            $calif->calificar($c, ($n + $i) % 6 === 0 ? 4 : 5, ($n + $i) % 6 === 3 ? 'Muy buena atención, puntuales.' : '');
        }
    }
    $n += $porDia;
}
// Clientes que dejaron de venir (para «Clientes por recuperar»)
$hr = 16;
foreach (['javier' => 9, 'paula' => 8, 'ricardo' => 11] as $ck => $semanas) {
    $hr++;
    $dia = $hoy->modify('-' . ($semanas * 7) . ' days');
    if ((int) $dia->format('w') === 0) $dia = $dia->modify('-1 day');
    foreach ([0, 21] as $antes) {
        $d2 = $dia->modify("-$antes days");
        if ((int) $d2->format('w') === 0) $d2 = $d2->modify('-1 day');
        $cid = $agenda->crearCita($sid, $dueno, $cli[$ck], $d2->format('Y-m-d') . " $hr:00", [$serv['corte']]);
        $cobrar($cid, $d2->format('Y-m-d') . " $hr:40");
    }
}
// Una falta de Luis (no llegó y no avisó)
$cf = $agenda->crearCita($sid, $carlos, $cli['luis'], $hoy->modify('-4 days')->format('Y-m-d') . ' 16:30', [$serv['corte']]);
$agenda->cambiarEstado($sid, $cf, 'no_asistio');
// Cierre de caja de ayer (o del último día con cobros)
$ayer = $hoy->modify('-1 day');
if ((int) $ayer->format('w') === 0) $ayer = $ayer->modify('-1 day');
$caja = new \TuSalon\Caja($db);
$esp = $caja->resumenDia($sid, $ayer->format('Y-m-d'))['efectivo_esperado'];
$caja->cerrar($sid, $ayer->format('Y-m-d'), (float) $esp - 1);
// Cuentas: arriendo semanal de Jorge y la cuenta cerrada de Carlos de hace dos semanas
$liq = new Liquidacion($db);
$liq->cargarArriendo($jorge, $hoy->modify('-14 days')->format('Y-m-d'));
$liq->cargarArriendo($jorge, $hoy->modify('-7 days')->format('Y-m-d'));
try { $liq->liquidar($carlos, $hoy->modify('-35 days')->format('Y-m-d'), $hoy->modify('-15 days')->format('Y-m-d')); } catch (Throwable) {}

// ---------------------------------------------------------------- 7. Paquetes, tarjetas y puntos vendidos
$pqPedro = $fid->venderPaquete($sid, $paqCortes, $cli['pedro'], 'transferencia', $hoy->modify('-10 days')->format('Y-m-d 11:00'));
$fid->venderPaquete($sid, $paqTinte, $cli['maria'], 'tarjeta', $hoy->modify('-20 days')->format('Y-m-d 15:00'));
$pqAndrea = $fid->venderPaquete($sid, $paqTinte, $cli['andrea'], 'transferencia', $hoy->modify('-6 days')->format('Y-m-d 12:00'));
$fid->venderTarjeta($sid, 30, ['de_nombre' => 'Sofía', 'para_nombre' => 'Valeria Chávez', 'para_telefono' => '0982345678', 'mensaje' => '¡Feliz cumpleaños!'],
                    'efectivo', $hoy->modify('-3 days')->format('Y-m-d 18:00'));
$tarjeta = $fid->venderTarjeta($sid, 20, ['de_nombre' => 'Mamá', 'para_nombre' => 'Mateo Villacís', 'mensaje' => 'Para tu corte de graduación'],
                    'deuna', $hoy->format('Y-m-d 10:15'));
$fid->ajustarPuntos($sid, $cli['andrea'], 50, 'Regalo por su cumpleaños');

// ---------------------------------------------------------------- 8. Ficha técnica de color (con fotos de antes y después)
$usuarioDueno = $q("SELECT u.*, u.rol FROM usuarios u WHERE u.salon_id = ? AND u.rol = 'dueno'", [$sid])->fetch();
$ft = new FichaTecnica($db);
$foto = function (string $archivo) use ($imagenes) {
    $f = rtrim($imagenes, '/') . '/' . $archivo;
    if ($imagenes === '' || !is_file($f)) return ['error' => UPLOAD_ERR_NO_FILE];
    $tmp = tempnam(sys_get_temp_dir(), 'demo');
    copy($f, $tmp);
    return ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp), 'name' => $archivo];
};
$ft->guardar($sid, $cli['maria'], $usuarioDueno, ['servicio' => 'Retoque de raíz', 'fecha' => $hoy->modify('-62 days')->format('Y-m-d'),
    'marca_tinte' => 'Igora Royal', 'tono' => '7.1', 'oxidante' => '20 vol', 'tiempo_minutos' => 35, 'formula' => '40 g 7.1 + 40 ml oxidante 20 vol',
    'resultado' => 'Cubrió las canas, tono parejo', 'profesional_id' => $ana]);
$ft->guardar($sid, $cli['maria'], $usuarioDueno, ['servicio' => 'Tinte completo', 'fecha' => $hoy->modify('-20 days')->format('Y-m-d'),
    'marca_tinte' => 'Igora Royal', 'tono' => '8.1 + 7.1', 'oxidante' => '20 vol', 'tiempo_minutos' => 40,
    'formula' => "30 g 8.1 + 10 g 7.1 + 60 ml oxidante 20 vol", 'resultado' => 'Rubio ceniza parejo', 'notas' => 'Cuero cabelludo sensible: usar protector.',
    'profesional_id' => $ana, 'autoriza_fotos' => 1], ['antes' => $foto('antes.jpg'), 'despues' => $foto('despues.jpg')]);

// ---------------------------------------------------------------- 9. Hoy: lo que ya pasó se cobró; lo que viene queda reservado
$hoyPlan = [
    ['09:00', $dueno, 'pedro', ['corte'], 'paquete'], ['09:30', $carlos, 'diego', ['combo'], null], ['10:00', $ana, 'andrea', ['tinte'], 'paq_andrea'],
    ['10:30', $jorge, 'mateo', ['corte'], null], ['11:30', $dueno, 'sofia', ['dama'], null], ['12:00', $carlos, 'fernando', ['corte'], null],
    ['15:00', $ana, 'carla', ['peinado'], null], ['16:00', $dueno, 'gabriela', ['raiz'], null], ['17:30', $carlos, 'luis', ['barba'], null],
    ['18:30', $jorge, 'valeria', ['corte'], null], ['19:30', $dueno, 'diego', ['barba'], null], ['20:00', $ana, 'sofia', ['peinado'], null],
    ['20:15', $carlos, 'mateo', ['corte'], null], ['20:30', $dueno, 'pedro', ['barba'], null],
];
$citaHoyAbierta = null;
foreach ($hoyPlan as [$h, $p, $ck, $sks, $extra]) {
    $inicio = new DateTimeImmutable($hoy->format('Y-m-d') . " $h");
    if ((int) $hoy->format('w') === 0 && $h >= '13:00') continue;
    $cid = $agenda->crearCita($sid, $p, $cli[$ck], $inicio->format('Y-m-d H:i'), array_map(fn($k) => $serv[$k], $sks));
    if ($inicio->modify('+40 minutes') < $ahora) {
        $paq = [];
        if ($extra === 'paquete') $paq = [$serv['corte'] => $pqPedro];
        if ($extra === 'paq_andrea') $paq = [$serv['tinte'] => $pqAndrea];
        $cobrar($cid, $inicio->modify('+35 minutes')->format('Y-m-d H:i'), $metodos[count($paq) + strlen($ck) % 4], $ck === 'sofia' ? 2 : 0,
                $ck === 'diego' ? [$prod['aceite'] => 1] : [], $paq);
    } elseif (!$citaHoyAbierta) {
        $citaHoyAbierta = $cid;
    }
}

// ---------------------------------------------------------------- 10. Mañana: agenda llena (recordatorios por enviar)
$manana = $hoy->modify('+1 day');
if ((int) $manana->format('w') === 0) $manana = $manana->modify('+1 day');
$m = $manana->format('Y-m-d');
$citasManana = [];
foreach ([
    ['09:00', $dueno, 'pedro', ['corte']], ['09:30', $ana, 'maria', ['tinte']], ['10:00', $jorge, 'luis', ['combo']], ['10:30', $carlos, 'diego', ['corte']],
    ['11:00', $dueno, 'gabriela', ['dama']], ['11:30', $carlos, 'fernando', ['combo']], ['12:00', $jorge, 'mateo', ['barba']], ['14:00', $carlos, 'mateo', ['corte']],
    ['14:30', $ana, 'valeria', ['keratina']], ['15:00', $dueno, 'carla', ['peinado']], ['16:00', $jorge, 'carla', ['corte']],
    ['16:30', $carlos, 'sofia', ['barba']], ['17:00', $dueno, 'andrea', ['raiz']], ['18:00', $jorge, 'valeria', ['combo']],
] as [$h, $p, $ck, $sks]) {
    if ($p === $carlos && (int) $manana->format('w') === 1) $p = $dueno;
    try { $citasManana[$ck] = $agenda->crearCita($sid, $p, $cli[$ck], "$m $h", array_map(fn($k) => $serv[$k], $sks)); } catch (Throwable) {}
}
$q("UPDATE citas SET confirmada_cliente_en = now() - interval '2 hours', recordatorio_en = now() - interval '3 hours', estado = 'confirmada' WHERE id = ?", [$citasManana['maria'] ?? 0]);
$q("UPDATE citas SET recordatorio_en = now() - interval '1 hour' WHERE id = ?", [$citasManana['luis'] ?? 0]);
// Una cita de Jorge con su cliente privado (el dueño ve la hora ocupada sin nombre)
$bryan = (int) $q("SELECT id FROM clientes WHERE salon_id = ? AND nombre = 'Bryan Molina'", [$sid])->fetchColumn();
try { $agenda->crearCita($sid, $jorge, $bryan, "$m 19:00", [$serv['combo']]); } catch (Throwable) {}

// Los avisos de las calificaciones del mes de ejemplo: se dejan 3 como leídos para que no tapen las solicitudes
$q("DELETE FROM notificaciones WHERE salon_id = ? AND id NOT IN (SELECT id FROM notificaciones WHERE salon_id = ? ORDER BY id DESC LIMIT 3)", [$sid, $sid]);
$q('UPDATE notificaciones SET leida = true, creada_en = now() - interval \'1 day\' WHERE salon_id = ?', [$sid]);

// ---------------------------------------------------------------- 11. Solicitudes en línea (pasado mañana)
$pm = $manana->modify('+1 day');
if ((int) $pm->format('w') === 0) $pm = $pm->modify('+1 day');
$libres = fn(int $p, int $min) => $agenda->horasLibres($sid, $p, $pm->format('Y-m-d'), $min);
$sol1 = $agenda->reservarOnline($sid, $carlos, $pm->format('Y-m-d'), $libres($carlos, 45)[6] ?? '11:00', [$serv['combo']], 'Pedro Ortiz', '0993456789');
$sol2 = $agenda->reservarOnline($sid, $ana, $pm->format('Y-m-d'), $libres($ana, 90)[8] ?? '15:00', [$serv['tinte']], 'Camila Ríos', '0987654321');
// Camila es nueva: le tocó anticipo del 30 % y ya subió el comprobante
$comp = $foto('comprobante.png');
if (($comp['error'] ?? 1) === UPLOAD_ERR_OK) (new Anticipos($db))->subirComprobante($sol2, $comp, 'transferencia', '000123456');

// ---------------------------------------------------------------- 12. Otros dos salones: Básica pagado y uno en demo
$b = (new Altas($db))->crearSalonConDueno('Barbería Solo Base', 'Marco Aguirre', '0990003333', 'basica@tusalon.tukanec.com', CLAVE, 'basica', 'super');
$q("UPDATE salones SET estado = 'activo', activo_hasta = CURRENT_DATE + 20 WHERE id = ?", [$b['salon_id']]);
$dm = (new Altas($db))->crearSalonConDueno('Peluquería en Demo', 'Lucía Herrera', '0990004444', 'endemo@tusalon.tukanec.com', CLAVE, 'basica', 'super');
$q("UPDATE salones SET prueba_hasta = CURRENT_DATE + 10 WHERE id = ?", [$dm['salon_id']]);

// ---------------------------------------------------------------- 13. Asistente IA (módulo extra, por cotización) y una pregunta frecuente de la Ayuda
$q("UPDATE salones SET modulos_extra = 'asistente_ia' WHERE id = ?", [$sid]);
require_once RAIZ . '/src/tukan_ia.php';
(new TukanIaFaqPdo($db))->agregar('¿Cómo registro un pago?',
    "1. Abre la cita en la **Agenda** y toca **Cobrar** (para alguien sin cita, toca **Cobrar** en **Hoy**).\n2. Revisa los servicios y el precio, y agrega la propina si la dejó.\n3. Elige cómo pagó (efectivo, transferencia, DeUna o tarjeta) y toca **Cobrar**.\nEl pago entra a la caja del día y a las cuentas del equipo.");

// ---------------------------------------------------------------- Datos para las capturas
$tok = fn(int $id) => (string) $q('SELECT token FROM citas WHERE id = ?', [$id])->fetchColumn();
$atendida = (int) $q("SELECT c.id FROM citas c WHERE c.salon_id = ? AND c.estado = 'atendida' AND c.cliente_id = ? AND NOT EXISTS (SELECT 1 FROM calificaciones k WHERE k.cita_id = c.id) ORDER BY c.inicio DESC LIMIT 1", [$sid, $cli['andrea']])->fetchColumn();
$tarjetaId = (int) $q('SELECT id FROM tarjetas_regalo WHERE salon_id = ? ORDER BY id DESC LIMIT 1', [$sid])->fetchColumn();
$salidas = [
    'SLUG' => $a['slug'], 'SALON' => $sid, 'MANANA' => $m, 'CITA_ABIERTA' => $citasManana['diego'] ?? $citaHoyAbierta,
    'CITA_ANTICIPO' => $sol2, 'TOKEN_CITA' => $tok($citasManana['andrea'] ?? $citasManana['pedro']), 'TOKEN_ATENDIDA' => $tok($atendida),
    'CLIENTE_MARIA' => $cli['maria'], 'CLIENTE_LUIS' => $cli['luis'], 'CLIENTE_ANDREA' => $cli['andrea'], 'PROD_SHAMPOO' => $prod['shampoo'],
    'TARJETA' => $tarjetaId, 'ANA' => $ana, 'CARLOS' => $carlos, 'SLUG_BASICA' => $b['slug'], 'PEDRO' => $cli['pedro'], 'CITA_COBRAR' => $citasManana['pedro'] ?? 0, 'MES' => $hoy->format('Y-m'),
];
foreach ($salidas as $k => $v) echo "$k=$v\n";
