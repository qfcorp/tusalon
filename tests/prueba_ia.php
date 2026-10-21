<?php
declare(strict_types=1);

/**
 * IA de Tukán en TuSalón (src/Ia.php, src/Asistente.php, src/tukan_ia.php) y «Enviar por correo» de la demo
 * (src/CorreoDemo.php, src/tukan_correo.php). La API de Anthropic y el SMTP son DE MENTIRA (tests/falsos/, copiados de
 * tukan-central/tests): no se gasta nada ni sale ningún correo.
 *   1. Migraciones 009 y 010 (se pueden repetir); el módulo «Asistente IA» viene en Completa y en Básica es extra; tope $4
 *   2. Sin /etc/tukan-ia.env: la IA queda apagada y la Ayuda responde con FAQ + manual
 *   3. Ayuda: pregunta nueva (IA), la misma (FAQ, sin llamar), una parecida, pausa, tope silencioso, 30 nuevas sin el módulo
 *   4. Agendar a partir de un mensaje: respeta la agenda real (hora ocupada → la más cercana), otro peluquero, JSON malo, tope
 *   5. Mensaje para clientes que no vuelven
 *   6. Preguntas frecuentes del súper (aprobar, editar, borrar, agregar)
 *   7. Conector v2: ia_uso e ia_tope (Anexo C)
 *   8. Correo de la demo: sello cifrado (sin la contraseña en claro), un solo uso, atado al super y al salón; envío por SMTP
 * Uso: TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_ia.php
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\') && is_file($f = RAIZ . '/src/' . substr($clase, 8) . '.php')) require $f;
});
require_once RAIZ . '/src/tukan_ia.php';
require_once RAIZ . '/src/tukan_correo.php';

use TuSalon\{Agenda, Altas, Asistente, CorreoDemo, Db, Ia, Modulos, TukanCentralV2};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real) ? abs((float) $esperado - (float) $real) < 0.0001 : $esperado === $real;
    if ($igual) { $ok++; echo "  OK    $nombre\n"; }
    else { $fallos[] = $nombre; echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n"; }
}
function titulo(string $t): void { echo "\n$t\n"; }
function puerto_libre(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $n = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($n, strrpos($n, ':') + 1);
}

if (!str_contains((string) getenv('TUSALON_DB_NAME'), 'prueba')) exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
$db = Db::conectar();
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) $db->exec(file_get_contents($m));
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();

// Servidores de mentira (puertos libres al azar) y su configuración
$tmp = sys_get_temp_dir() . '/tusalon_ia_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$pIa = puerto_libre();
$pSmtp = puerto_libre();
$logApi = "$tmp/api.jsonl";
ini_set('error_log', "$tmp/php.log");
$procIa = proc_open(['python3', RAIZ . '/tests/falsos/anthropic_falso.py', (string) $pIa, $logApi], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/ia.err", 'w']], $t1);
$procSmtp = proc_open(['python3', RAIZ . '/tests/falsos/smtp_falso.py', '--puerto', (string) $pSmtp, '--modo', 'plano', '--dir', "$tmp/correo",
                       '--usuario', 'info@tukansoftware.com', '--clave', 'clave-buzon-777'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/smtp.err", 'w']], $t2);
register_shutdown_function(function () use ($procIa, $procSmtp, $tmp) {
    foreach ([$procIa, $procSmtp] as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($tmp));
});
for ($i = 0; $i < 50 && (!@fsockopen('127.0.0.1', $pIa) || !@fsockopen('127.0.0.1', $pSmtp)); $i++) usleep(100000);
$pedidos = fn() => is_file($logApi) ? array_map(fn($l) => json_decode($l, true), file($logApi, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
$nPed = fn() => count($pedidos());
$CLAVE = 'sk-ant-prueba-buena-0123456789';
$envIa = function (array $extra = []) use ($tmp, $pIa, $CLAVE): void {
    $v = ['TUKAN_IA_CLAVE' => $CLAVE, 'TUKAN_IA_URL' => "http://127.0.0.1:$pIa", 'TUKAN_IA_ESPERA_MS' => '0'] + $extra;
    $f = "$tmp/ia_" . bin2hex(random_bytes(3)) . '.env';
    file_put_contents($f, implode("\n", array_map(fn($k) => "$k={$v[$k]}", array_keys($v))) . "\n");
    putenv("TUKAN_IA_ENV=$f");
    tukan_ia_config(true);
};
foreach (['TUKAN_IA_CLAVE', 'TUKAN_IA_PAUSA', 'TUKAN_IA_URL', 'ANTHROPIC_API_KEY'] as $k) putenv($k);
$prohibidas = fn(string $t) => (bool) preg_match('/l[ií]mite|cupo|saldo|cr[eé]dito/iu', $t);

// ---------------------------------------------------------------------------------------------
titulo('1. Migración 009 y módulo «Asistente IA»');
$db->exec(file_get_contents(RAIZ . '/db/migraciones/009_ia.sql'));
$db->exec(file_get_contents(RAIZ . '/db/migraciones/009_ia.sql'));
check('La migración 009 se puede correr varias veces (tablas y columna)', [1, 1, 1], [
    $cuenta("SELECT count(*) FROM information_schema.tables WHERE table_name = 'tukan_ia_faq'"),
    $cuenta("SELECT count(*) FROM information_schema.tables WHERE table_name = 'tukan_ia_gasto'"),
    $cuenta("SELECT count(*) FROM information_schema.columns WHERE table_name = 'salones' AND column_name = 'ia_tope_usd'")]);
$mods = new Modulos($db);
$cat = $mods->catalogo();
check('Catálogo: asistente_ia «Asistente IA», grupo Extras, precio 0 (por cotización)', ['Asistente IA', 'extras', 0.0], [$cat['asistente_ia']['nombre'], $cat['asistente_ia']['grupo'], $cat['asistente_ia']['precio_mensual']]);
check('Viene INCLUIDO en Completa (el plan más caro) y NO en Básica (ahí es extra)', [true, false], [in_array('asistente_ia', $mods->planes()['completa']['modulos'], true), in_array('asistente_ia', $mods->planes()['basica']['modulos'], true)]);
check('Completa es el plan más caro', 'completa', $db->query('SELECT codigo FROM planes ORDER BY precio_mensual DESC LIMIT 1')->fetchColumn());
// Migración 010: si Completa se guardó como lista (no '*'), le agrega asistente_ia una sola vez; no toca Básica
$db->exec("UPDATE planes SET modulos = 'reservas_linea,reportes' WHERE codigo = 'completa'");
$db->exec(file_get_contents(RAIZ . '/db/migraciones/010_ia_en_completa.sql'));
$db->exec(file_get_contents(RAIZ . '/db/migraciones/010_ia_en_completa.sql'));
check('Migración 010 (dos veces): Completa con lista → agrega asistente_ia una vez; Básica sigue sin él', ['reservas_linea,reportes,asistente_ia', ''],
      [$db->query("SELECT modulos FROM planes WHERE codigo = 'completa'")->fetchColumn(), $db->query("SELECT modulos FROM planes WHERE codigo = 'basica'")->fetchColumn()]);
$db->exec("UPDATE planes SET modulos = '*' WHERE codigo = 'completa'");
$db->exec(file_get_contents(RAIZ . '/db/migraciones/010_ia_en_completa.sql'));
check('… y Completa = * se queda en * (que ya trae el Asistente IA)', '*', $db->query("SELECT modulos FROM planes WHERE codigo = 'completa'")->fetchColumn());
$mods->olvidar();
check('La versión de la librería es la del Panel (copiada sin cambios)', [md5_file(RAIZ . '/src/tukan_ia.php'), true], [md5_file(RAIZ . '/src/tukan_ia.php'), defined('TUKAN_IA_VERSION')]);

$alta = fn(string $nombre) => (new Altas($db))->crearSalonConDueno($nombre, 'Dueña ' . $nombre, '0991112222', 'd' . bin2hex(random_bytes(4)) . '@prueba.ec', 'claveSegura1', 'basica', 'super');
$SB = $alta('Salón Sin Módulo')['salon_id'];          // pagado, plan Básica, sin el módulo
$SM = $alta('Salón Con Asistente')['salon_id'];       // pagado, plan Básica + asistente_ia
$db->exec("UPDATE salones SET estado = 'activo', activo_hasta = CURRENT_DATE + 30 WHERE id IN ($SB, $SM)");
$db->exec("UPDATE salones SET modulos_extra = 'asistente_ia' WHERE id = $SM");
$SC = $alta('Salón Completa')['salon_id'];            // pagado, plan Completa (trae el Asistente IA)
$db->exec("UPDATE salones SET plan_codigo = 'completa', estado = 'activo', activo_hasta = CURRENT_DATE + 30 WHERE id = $SC");
$mods->olvidar();
check('Sin el módulo: tope sugerido $0,50 · con el módulo comprado aparte (Básica) $4,00', [0.5, 4.0, false, true], [Ia::tope($db, $SB), Ia::tope($db, $SM), Ia::conModulo($db, $SB), Ia::conModulo($db, $SM)]);
check('Plan Completa: trae el Asistente IA sin comprarlo aparte y su tope es $4,00', [true, 4.0, [], true],
      [Ia::conModulo($db, $SC), Ia::tope($db, $SC), $mods->detalle($mods->salon($SC))['extra'], in_array('asistente_ia', $mods->detalle($mods->salon($SC))['del_plan'], true)]);
check('Básica con el Asistente IA: va como extra (no del plan)', [['asistente_ia'], false],
      [$mods->detalle($mods->salon($SM))['extra'], in_array('asistente_ia', $mods->detalle($mods->salon($SM))['del_plan'], true)]);
check('Ia::TOPE_CON_MODULO = 4,00 y TOPE_SIN_MODULO = 0,50', [4.0, 0.5], [Ia::TOPE_CON_MODULO, Ia::TOPE_SIN_MODULO]);
$db->exec("UPDATE salones SET estado = 'cancelado' WHERE id = $SC");   // fuera del resto de esta prueba
Ia::guardarTope($db, $SB, 1.25);
check('Tope propio (lo pone Tukán) gana; null vuelve al sugerido', [1.25, 0.5], [Ia::tope($db, $SB), (Ia::guardarTope($db, $SB, null) ?? Ia::tope($db, $SB))]);

// ---------------------------------------------------------------------------------------------
titulo('2. Sin /etc/tukan-ia.env: IA apagada, la Ayuda sigue con el manual');
putenv("TUKAN_IA_ENV=$tmp/no_existe.env");
tukan_ia_config(true);
check('Sin configuración la IA está apagada', [false, false], [tukan_ia_activa(), tukan_ia_estado()['configurada']]);
$a = Ia::ayuda($db, $SB, '¿Cómo cobro una cita?');
check('Ayuda sin IA: responde con el manual', ['manual', true], [$a['fuente'], str_contains($a['respuesta'], 'Esto dice el manual de TuSalón')]);
check('… sin llamar a la API ni guardar FAQ', [0, 0], [$nPed(), $cuenta('SELECT count(*) FROM tukan_ia_faq')]);
check('… y sin palabras prohibidas', false, $prohibidas($a['respuesta']));
$r = (new Asistente($db, $SM))->agendar('Quiero un corte mañana en la mañana');
check('Agendar sin IA: ok = false y sin llamar', [false, 0], [$r['ok'], $nPed()]);
check('El manual que lee la Ayuda tiene el capítulo «Ayuda e Inteligencia artificial»', true, str_contains(Ia::manual(), 'Ayuda e Inteligencia artificial'));

// ---------------------------------------------------------------------------------------------
titulo('3. Ayuda con la IA (de mentira)');
$envIa();
$antes = $nPed();
$a = Ia::ayuda($db, $SB, '¿Cómo registro un pago de una cita?');
check('Pregunta nueva: la responde la IA (1 llamada)', ['ia', 1], [$a['fuente'], $nPed() - $antes]);
$p = $pedidos()[$nPed() - 1];
check('… con el modelo rápido, las secciones del manual y la pregunta como DATO', [true, true, true],
      [$p['modelo'] === 'claude-haiku-5-5', str_contains($p['texto'], '<manual>'), str_contains($p['texto'], '<pregunta>')]);
check('… y se guarda como FAQ automática sin aprobar', [1, 'automatica', 0], [$cuenta('SELECT count(*) FROM tukan_ia_faq'),
      $db->query('SELECT origen FROM tukan_ia_faq')->fetchColumn(), (int) $db->query('SELECT aprobada FROM tukan_ia_faq')->fetchColumn()]);
$g = Ia::gastoMes($db, $SB);
check('El gasto del salón queda anotado (1 llamada, 1 respuesta nueva, costo > 0)', [1, 1, true], [$g['llamadas'], $g['ayudas_nuevas'], $g['costo'] > 0]);
$antes = $nPed();
$a2 = Ia::ayuda($db, $SM, '¿cómo registro un pago de una cita');
check('La misma pregunta (otro salón): sale de la FAQ, sin llamar', ['faq', 0, $a['respuesta']], [$a2['fuente'], $nPed() - $antes, $a2['respuesta']]);
$a3 = Ia::ayuda($db, $SM, '¿Cómo registro el pago de la cita?');
check('Una parecida también sale de la FAQ', ['faq', 0], [$a3['fuente'], $nPed() - $antes]);
check('… suma usos y ahorradas', [true, 2], [(int) $db->query('SELECT usos FROM tukan_ia_faq')->fetchColumn() >= 3, Ia::gastoMes($db, $SM)['ahorradas']]);
$envIa(['TUKAN_IA_PAUSA' => '1']);
$antes = $nPed();
$a = Ia::ayuda($db, $SB, '¿Cómo agrego un peluquero nuevo?');
check('En pausa: manual, sin llamar', ['manual', 0], [$a['fuente'], $nPed() - $antes]);
check('… sin decir por qué (ni «límite», «cupo» o «saldo», aunque el manual los use)', false, $prohibidas($a['respuesta']) || str_contains(mb_strtolower($a['respuesta']), 'pausa'));
check('Ia::sinPalabras() cambia esas palabras del manual por otras', 'el máximo de 4 · el máximo de personas · Ver monto · turnos de mañana', Ia::sinPalabras('el cupo de 4 · el límite de personas · Ver saldo · cupos de mañana'));
$envIa();
Ia::guardarTope($db, $SB, 0.0);
$a = Ia::ayuda($db, $SB, '¿Cómo agrego un peluquero nuevo?');
check('Tope silencioso del salón: manual, sin llamar y sin palabras prohibidas', ['manual', 0, false], [$a['fuente'], $nPed() - $antes, $prohibidas($a['respuesta'])]);
Ia::guardarTope($db, $SB, null);
$db->exec("UPDATE tukan_ia_gasto SET ayudas_nuevas = 30 WHERE negocio_id = '$SB'");
$a = Ia::ayuda($db, $SB, '¿Cómo cambio el horario del salón?');
check('Sin el módulo: 30 respuestas nuevas al mes y después el manual (sin llamar)', ['manual', 0], [$a['fuente'], $nPed() - $antes]);
$db->exec("INSERT INTO tukan_ia_gasto (negocio_id, mes, ayudas_nuevas) VALUES ('$SM', '" . date('Y-m') . "', 30) ON CONFLICT (negocio_id, mes) DO UPDATE SET ayudas_nuevas = 30");
$a = Ia::ayuda($db, $SM, '¿Cómo cambio el horario del salón?');
check('Con el módulo: no hay tope de cantidad (vale el tope en USD) → IA', ['ia', 1], [$a['fuente'], $nPed() - $antes]);
$a = Ia::ayuda($db, $SM, '   ');
check('Pregunta vacía: pide más detalle, sin llamar', ['vacia', 1], [$a['fuente'], $nPed() - $antes]);
$s = [];
$n = 0;
for ($i = 0; $i < 12; $i++) $n += Ia::puedePreguntar($s, 1000) ? 1 : 0;
check('Máximo 10 preguntas por minuto por usuario', [10, true], [$n, Ia::puedePreguntar($s, 1061)]);
check('La respuesta se muestra segura (escapa HTML, **negritas**)', "Hola <strong>uno</strong> &lt;b&gt;<br />\n", Ia::html("Hola **uno** <b>\n"));

// ---------------------------------------------------------------------------------------------
titulo('4. Agendar a partir de un mensaje (respeta la agenda real)');
$ag = new Agenda($db);
$duena = (int) $db->query("SELECT id FROM profesionales WHERE salon_id = $SM ORDER BY id LIMIT 1")->fetchColumn();
$svc = $db->query("SELECT nombre, id FROM servicios WHERE salon_id = $SM")->fetchAll(PDO::FETCH_KEY_PAIR);
[$corte, $tinte] = [(int) $svc['Corte de dama'], (int) $svc['Tinte']];
$vie = (new DateTimeImmutable('today'))->modify('+2 days')->modify('next friday')->format('Y-m-d');
$ag->crearCita($SM, $duena, null, "$vie 15:00", [$corte, $tinte]);   // 15:00 a 17:15 ocupado
$asis = new Asistente($db, $SM);
$json = fn(array $j) => 'RESPONDER:' . json_encode($j, JSON_UNESCAPED_UNICODE);
$antes = $nPed();
$r = $asis->agendar("Hola, soy Marta. Quiero corte y tinte el viernes en la tarde con la dueña " . $json(['servicios' => [$corte, $tinte], 'profesional_id' => $duena, 'fecha' => $vie, 'hora' => '15:00', 'cliente' => 'Marta', 'nota' => '']));
$p = $pedidos()[$nPed() - 1];
check('La IA recibe servicios, peluqueros, las horas libres de la agenda y el mensaje como DATO', [true, true, true, true],
      [str_contains($p['texto'], "id $corte: Corte de dama"), str_contains($p['texto'], 'Horas libres'), str_contains($p['texto'], "$vie (viernes)"), str_contains($p['texto'], '<mensaje>')]);
check('… y pide SOLO JSON (1 llamada)', [true, 1], [str_contains($p['system'], 'SOLO con un objeto JSON'), $nPed() - $antes]);
$b = $r['borrador'] ?? [];
check('Hora pedida ocupada (15:00): propone la más cercana libre de esa tarde (12:45)', [true, false, $vie, '12:45', $duena], [$r['ok'], $b['exacta'] ?? null, $b['fecha'] ?? null, $b['hora'] ?? null, $b['profesional_id'] ?? null]);
check('… que la agenda confirma libre para 135 minutos', true, in_array('12:45', $ag->horasLibres($SM, $duena, $vie, 135), true));
check('… con los servicios y el nombre del cliente', [['Corte de dama', 'Tinte'], 'Marta', 135], [$b['servicios_nombres'] ?? null, $b['cliente'] ?? null, $b['minutos'] ?? null]);
$enlace = Asistente::enlaceCita($b, 7);
check('El borrador abre «Nueva cita» con todo puesto (se confirma ahí)', true, str_contains($enlace, 'r=cita_nueva') && str_contains($enlace, "fecha=$vie") && str_contains($enlace, 'hora=12%3A45')
      && str_contains($enlace, 'servicios=' . rawurlencode("$corte,$tinte")) && str_contains($enlace, 'cliente_id=7'));
$r = $asis->agendar('Corte el viernes a las 10 ' . $json(['servicios' => [$corte], 'profesional_id' => $duena, 'fecha' => $vie, 'hora' => '10:00']));
check('Hora libre: la deja tal cual (exacta)', [true, true, '10:00'], [$r['ok'], $r['borrador']['exacta'] ?? null, $r['borrador']['hora'] ?? null]);
$db->exec("INSERT INTO profesionales (salon_id, nombre, tipo) VALUES ($SM, 'Lucía', 'porcentaje')");
$lucia = (int) $db->lastInsertId();
$r = $asis->agendar('Corte y tinte el viernes 3 pm, con quien sea ' . $json(['servicios' => [$corte, $tinte], 'profesional_id' => null, 'fecha' => $vie, 'hora' => '15:00']));
check('Sin peluquero pedido: busca a otro libre a esa hora (Lucía 15:00)', [true, true, $lucia, '15:00'], [$r['ok'], $r['borrador']['exacta'] ?? null, $r['borrador']['profesional_id'] ?? null, $r['borrador']['hora'] ?? null]);
$dom = (new DateTimeImmutable($vie))->modify('+2 days')->format('Y-m-d');   // domingo: cerrado
$r = $asis->agendar('Corte el domingo ' . $json(['servicios' => [$corte], 'profesional_id' => $duena, 'fecha' => $dom, 'hora' => '10:00']));
check('Día cerrado (domingo): pasa al siguiente día con hora libre en la misma franja', [true, false, (new DateTimeImmutable($dom))->modify('+1 day')->format('Y-m-d'), 'manana'],
      [$r['ok'], $r['borrador']['exacta'] ?? null, $r['borrador']['fecha'] ?? null, Asistente::franja($r['borrador']['hora'] ?? '')]);
$antes = $nPed();
$r = $asis->agendar('Un servicio que no existe ' . $json(['servicios' => [999999], 'profesional_id' => $duena, 'fecha' => $vie, 'hora' => '10:00']));
check('JSON que no pasa la validación (servicio que no existe): reintenta una vez y no inventa', [false, 'respuesta', 2], [$r['ok'], $r['motivo'], $nPed() - $antes]);
$r = $asis->agendar('Fecha pasada ' . $json(['servicios' => [$corte], 'profesional_id' => $duena, 'fecha' => '2020-01-01', 'hora' => '10:00']));
check('Fecha pasada: no pasa la validación', [false, 'respuesta'], [$r['ok'], $r['motivo']]);
$r = $asis->agendar('Hora mal ' . $json(['servicios' => [$corte], 'profesional_id' => $duena, 'fecha' => $vie, 'hora' => '25:00']));
check('Hora inválida: no pasa la validación', [false, 'respuesta'], [$r['ok'], $r['motivo']]);
Ia::guardarTope($db, $SM, 0.0);
$antes = $nPed();
$r = $asis->agendar('Corte el viernes ' . $json(['servicios' => [$corte], 'profesional_id' => $duena, 'fecha' => $vie, 'hora' => '10:00']));
check('Tope silencioso: no llama (motivo tope)', [false, 'tope', 0], [$r['ok'], $r['motivo'], $nPed() - $antes]);
Ia::guardarTope($db, $SM, null);
check('El mensaje neutro (mismo texto sea cual sea el motivo) trae el enlace para hacerlo a mano', true,
      str_contains(Ia::neutroHtml('texto', '/?r=cita_nueva'), 'En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano aquí: <a href="/?r=cita_nueva">'));
check('… sin palabras prohibidas', false, $prohibidas(Ia::neutroHtml('texto', '/?r=cita_nueva')));
$v = $asis->verificar(['servicios' => [$corte], 'profesional_id' => $duena, 'fecha' => $vie, 'hora' => '15:30']);
check('verificar(): una hora ocupada nunca sale como propuesta', false, in_array($v['hora'], ['15:00', '15:15', '15:30', '15:45', '16:00', '16:15', '16:30', '16:45', '17:00'], true) && $v['profesional_id'] === $duena);
check('Rangos de horas libres para la IA', '09:00-09:30, 11:00', Asistente::rangos(['09:00', '09:15', '09:30', '11:00'], 15));

// ---------------------------------------------------------------------------------------------
titulo('5. Mensaje para clientes que no vuelven');
$db->exec("UPDATE salones SET nombre = 'Salón RESPONDER:Hola Rosa, te extrañamos en el salón. ¿Te esperamos esta semana?' WHERE id = $SM");
$cli = (int) $db->query("INSERT INTO clientes (salon_id, nombre, telefono) VALUES ($SM, 'Rosa Pérez', '0987776666') RETURNING id")->fetchColumn();
$c = $ag->crearCita($SM, $duena, $cli, (new DateTimeImmutable('-10 weeks'))->format('Y-m-d') . ' 10:00', [$corte]);
$db->exec("UPDATE citas SET estado = 'atendida' WHERE id = $c");
check('Rosa sale en la lista de clientes que no vuelven', true, in_array($cli, array_map(fn($x) => (int) $x['id'], $asis->porRecuperar(new DateTimeImmutable())), true));
$antes = $nPed();
$r = $asis->mensajeRecuperar($cli);
$p = $pedidos()[$nPed() - 1];
check('Arma el texto con la IA (1 llamada, datos como DATO)', [true, 1, true, true], [$r['ok'], $nPed() - $antes, str_contains($p['texto'], '<datos>'), str_contains($p['texto'], 'Semanas sin venir: 10')]);
check('… con el texto y el enlace de WhatsApp al celular del cliente', [true, true], [str_contains($r['texto'] ?? '', 'Hola Rosa'), str_starts_with((string) ($r['wa'] ?? ''), 'https://wa.me/593987776666')]);
$otro = (int) $db->query("INSERT INTO clientes (salon_id, nombre) VALUES ($SB, 'De Otro Salón') RETURNING id")->fetchColumn();
check('Un cliente de otro salón: no (sin llamar)', [false, 'datos'], [($x = $asis->mensajeRecuperar($otro))['ok'], $x['motivo']]);
$envIa(['TUKAN_IA_PAUSA' => '1']);
check('En pausa: ok = false (la pantalla muestra el mensaje neutro)', [false, 'pausa'], [($x = $asis->mensajeRecuperar($cli))['ok'], $x['motivo']]);
$envIa();

// ---------------------------------------------------------------------------------------------
titulo('6. Preguntas frecuentes del súper usuario');
$faq = Ia::faq($db);
$id = $faq->agregar('¿Dónde veo la caja del día?', 'En **Caja**.');
$f = $faq->obtener($id);
check('Agregar a mano: queda aprobada y «manual»', ['manual', 1], [$f['origen'], $f['aprobada']]);
$auto = (int) $db->query("SELECT id FROM tukan_ia_faq WHERE origen = 'automatica' ORDER BY id LIMIT 1")->fetchColumn();
check('Por revisar: las automáticas sin aprobar', true, in_array($auto, array_column($faq->listar('sin_aprobar'), 'id'), true));
$faq->aprobar($auto);
check('Aprobar', 1, $faq->obtener($auto)['aprobada']);
$faq->editar($auto, '¿Cómo registro un pago de una cita?', 'Paso 1. Abra la cita. Paso 2. Toque **Cobrar**.');
check('Editar: cambia la respuesta y pasa a «manual» aprobada', ['manual', 1, true], [$faq->obtener($auto)['origen'], $faq->obtener($auto)['aprobada'], str_contains($faq->obtener($auto)['respuesta'], 'Cobrar')]);
$antes = $nPed();
check('La Ayuda ya responde con la versión editada (para todos)', [true, 0], [str_contains(Ia::ayuda($db, $SB, '¿Cómo registro un pago de una cita?')['respuesta'], 'Toque **Cobrar**'), $nPed() - $antes]);
$faq->borrar($id);
check('Borrar', null, $faq->obtener($id));

// ---------------------------------------------------------------------------------------------
titulo('7. Conector v2: ia_uso e ia_tope (Anexo C)');
$SECRETO = str_repeat('ab12', 16);
putenv('TUKAN_CENTRAL_ID=tusalon');
putenv("TUKAN_CENTRAL_SECRET=$SECRETO");
putenv('TUKAN_CENTRAL_URL=https://panel.prueba.ec');
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$pedir = function (string $accion, array|string $cuerpo = []) use ($db, $b64, $SECRETO): array {
    $c = is_string($cuerpo) ? $cuerpo : json_encode($cuerpo === [] ? new stdClass() : $cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $db->exec('DELETE FROM tukan_central_pedidos');
    $t = time();
    $p = ['v' => 2, 'tipo' => $accion, 'inst' => 'tusalon', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'rol' => 'dueno', 'iat' => $t,
          'exp' => $t + ($accion === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)), 'bh' => $b64(hash('sha256', $c, true))];
    $a = $b64(json_encode($p));
    return (new TukanCentralV2($db))->procesar($accion, 'POST', 'application/json', $c, $a . '.' . $b64(hash_hmac('sha256', $a, $SECRETO, true)), '198.51.100.20');
};
$r = $pedir('capacidades');
check('capacidades: acciones con ia_uso y ejecutar con ia_tope', [true, true], [in_array('ia_uso', $r[1]['acciones'], true), in_array('ia_tope', $r[1]['ejecutar'], true)]);
$r = $pedir('ia_uso', ['mes' => '2020-01']);
check('ia_uso de un mes sin datos: listas vacías', [200, [], 0.0, 0], [$r[0], $r[1]['negocios'], (float) $r[1]['totales']['costo'], $r[1]['totales']['llamadas']]);
$db->exec("UPDATE tukan_ia_gasto SET costo_usd = 0.2 WHERE negocio_id = '$SB'");
$db->exec("UPDATE tukan_ia_gasto SET costo_usd = 0.9 WHERE negocio_id = '$SM'");
$r = $pedir('ia_uso', ['mes' => date('Y-m')]);
$j = $r[1];
check('ia_uso con dos salones: el que más gastó primero', [200, 'ia_uso', [(string) $SM, (string) $SB]], [$r[0], $j['accion'], array_column($j['negocios'], 'cliente_id')]);
check('… con nombre, tope y si tiene el módulo', [4.0, true, 0.5, false], [$j['negocios'][0]['tope'], $j['negocios'][0]['modulo'], $j['negocios'][1]['tope'], $j['negocios'][1]['modulo']]);
check('… totales, FAQ, modelos y versión de la librería', [1.1, true, 'claude-haiku-5-5', '1', 'asistente_ia', 'USD'], [$j['totales']['costo'], $j['faq']['total'] >= 1, $j['modelo_rapido'], $j['version_libreria'], $j['modulo'], $j['moneda']]);
check('… y NO trae la clave', false, str_contains(json_encode($j), $CLAVE) || str_contains(json_encode($j), 'sk-ant'));
check('ia_uso sin cuerpo: el mes actual', [200, date('Y-m')], [($x = $pedir('ia_uso'))[0], $x[1]['mes']]);
check('Mes inválido → 422 invalido (campo mes)', [422, 'invalido', 'mes'], [($x = $pedir('ia_uso', ['mes' => '2026-13']))[0], $x[1]['codigo'], $x[1]['campo'] ?? null]);
check('Campo desconocido → 422', [422, 'invalido'], [($x = $pedir('ia_uso', ['mes' => '2026-10', 'otro' => 1]))[0], $x[1]['codigo']]);
$ej = fn(array $datos) => $pedir('ejecutar', ['operacion_id' => bin2hex(random_bytes(16)), 'accion' => 'ia_tope', 'cliente_id' => (string) $SB, 'datos' => $datos]);
$r = $ej(['tope_usd' => 5]);
check('ejecutar ia_tope 5: guarda el tope del salón', [200, 5.0, 5.0], [$r[0], (float) $r[1]['resultado']['tope_usd'], Ia::tope($db, $SB)]);
check('… y queda en registro_admin', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE salon_id = $SB AND accion LIKE 'Panel Tukán Central · Tope de IA del mes: \$5,00%'"));
$r = $ej(['tope_usd' => null]);
check('ia_tope null: vuelve al sugerido', [200, null, 0.5], [$r[0], $r[1]['resultado']['tope_usd'], Ia::tope($db, $SB)]);
check('ia_tope negativo → 422', [422, 'tope_usd'], [($x = $ej(['tope_usd' => -1]))[0], $x[1]['campo'] ?? null]);
check('ia_tope con texto → 422', 422, $ej(['tope_usd' => 'mucho'])[0]);
$r = $pedir('cliente', ['id' => (string) $SB]);
$acc = array_values(array_filter($r[1]['cliente']['acciones_posibles'], fn($a) => $a['accion'] === 'ia_tope'));
check('La ficha del cliente ofrece «Tope de IA» con un campo tope_usd tipo dinero', ['tope_usd', 'dinero'], [$acc[0]['campos'][0]['campo'] ?? null, $acc[0]['campos'][0]['tipo'] ?? null]);
foreach (['TUKAN_CENTRAL_ID', 'TUKAN_CENTRAL_SECRET', 'TUKAN_CENTRAL_URL'] as $k) putenv($k);

// ---------------------------------------------------------------------------------------------
titulo('8. Correo de la demo (sello cifrado y SMTP de mentira)');
putenv("TUKAN_CORREO_ENV=$tmp/no_existe.env");
tukan_correo_config(true);
check('Sin /etc/tukan-correo.env: no está listo («Configure el correo de Tukán»)', false, CorreoDemo::listo());
file_put_contents("$tmp/correo.env", "TUKAN_SMTP_HOST=127.0.0.1\nTUKAN_SMTP_PUERTO=$pSmtp\nTUKAN_SMTP_SEGURIDAD=ninguna\nTUKAN_SMTP_USUARIO=info@tukansoftware.com\n"
    . "TUKAN_SMTP_CLAVE=clave-buzon-777\nTUKAN_CORREO_DE=info@tukansoftware.com\nTUKAN_CORREO_NOMBRE=Tukán Software\n");
putenv("TUKAN_CORREO_ENV=$tmp/correo.env");
tukan_correo_config(true);
check('Con el archivo: listo', true, CorreoDemo::listo());
$ses = [];
$datos = ['usuario' => 'rosa@salon.ec', 'clave' => 'Xy7ClaveDemo9', 'enlace' => 'https://tusalon.tukanec.com', 'hasta' => '2026-10-30', 'contacto' => 'Rosa Pérez', 'negocio' => 'Salón Rosa'];
$sello = CorreoDemo::sellar($datos, 1, $SB, $ses, 1000);
check('El sello no lleva la contraseña en claro, ni la sesión', [false, false], [str_contains($sello, 'Xy7ClaveDemo9') || str_contains(base64_decode(strtr(explode('.', $sello)[1], '-_', '+/')), 'Xy7ClaveDemo9'),
      str_contains(json_encode($ses), 'Xy7ClaveDemo9')]);
check('Se abre con el mismo super usuario, salón y sesión', $datos, CorreoDemo::abrir($sello, 1, $SB, $ses, 1001)['d'] ?? null);
check('Otro super usuario u otro salón: no abre', [null, null], [CorreoDemo::abrir($sello, 2, $SB, $ses, 1001), CorreoDemo::abrir($sello, 1, $SM, $ses, 1001)]);
check('Otra sesión (sin la llave): no abre', null, CorreoDemo::abrir($sello, 1, $SB, [], 1001));
check('Sello cambiado: no abre', null, CorreoDemo::abrir(substr($sello, 0, -3) . (str_ends_with($sello, 'AAA') ? 'BBB' : 'AAA'), 1, $SB, $ses, 1001));
check('Después de 30 minutos: no abre', null, CorreoDemo::abrir($sello, 1, $SB, $ses, 1000 + CorreoDemo::DURA_SEG + 1));
$m = CorreoDemo::mensaje($datos);
check('Correo «Su demo de TuSalón está lista» con usuario, contraseña y fecha, sin «gratis» ni precios', [true, true, true, false],
      ['Su demo de TuSalón está lista' === $m['asunto'], str_contains($m['texto'], 'Contraseña: Xy7ClaveDemo9'), str_contains($m['texto'], '30 de octubre de 2026'), (bool) preg_match('/gratis|\$\d/i', $m['texto'])]);
$ok1 = CorreoDemo::enviar($datos, 'rosa@salon.ec', $err);
$emls = glob("$tmp/correo/*.eml") ?: [];
$eml = $emls ? (string) file_get_contents($emls[0]) : '';
check('Se envía por SMTP desde info@tukansoftware.com a quien se escribió', [true, 1, true, true], [$ok1, count($emls), str_contains($eml, 'From: =?UTF-8?B?') && str_contains($eml, '<info@tukansoftware.com>'), str_contains($eml, 'rosa@salon.ec')]);
// El SMTP anota cada conversación al cerrarla (la primera es la prueba de que ya escuchaba): se busca la que trajo el correo
$conCorreo = fn() => array_values(array_filter(array_map(fn($l) => json_decode($l, true), @file("$tmp/correo/registro.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), fn($x) => !empty($x['archivos'])));
for ($i = 0; $i < 30 && !$conCorreo(); $i++) usleep(100000);
$reg = $conCorreo();
check('… con usuario y clave del buzón correctos (el registro del SMTP no guarda la clave)', [true, false], [$reg[0]['auth_ok'] ?? null, str_contains((string) file_get_contents("$tmp/correo/registro.jsonl"), 'clave-buzon-777')]);
CorreoDemo::gastar(explode('.', $sello)[0], $ses);
check('Al enviar bien se borra la llave: el mismo sello ya no sirve', null, CorreoDemo::abrir($sello, 1, $SB, $ses, 1002));
file_put_contents("$tmp/correo_mal.env", str_replace('clave-buzon-777', 'otra-clave', (string) file_get_contents("$tmp/correo.env")));
putenv("TUKAN_CORREO_ENV=$tmp/correo_mal.env");
tukan_correo_config(true);
$ok2 = CorreoDemo::enviar($datos, 'rosa@salon.ec', $err2);
check('Clave del buzón equivocada: falla con un error que no trae ninguna clave', [false, true, false], [$ok2, (string) $err2 !== '', str_contains((string) $err2, 'otra-clave') || str_contains((string) $err2, 'Xy7ClaveDemo9')]);
putenv('TUKAN_CORREO_ENV');

echo "\n==================================================\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
foreach ($fallos as $f) echo "  - $f\n";
exit($fallos ? 1 : 0);
