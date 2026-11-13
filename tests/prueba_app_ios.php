<?php
declare(strict_types=1);
/**
 * App de iPhone (regla 3.1.1 de Apple, 13 nov 2026): en_app_ios(), en_app_tienda() y que lo de Google Play nunca salga en el iPhone.
 * No necesita base de datos. Uso: php tests/prueba_app_ios.php   (las pantallas se prueban en tests/prueba_web_ios.sh)
 */
define('TUKAN_SIN_SESION', true);
require dirname(__DIR__) . '/app/bootstrap.php';
$ok = 0; $fallas = 0;
function check(string $n, $esperado, $real): void
{
    global $ok, $fallas;
    if ($esperado === $real) { $ok++; echo "  OK    $n\n"; }
    else { $fallas++; echo "  FALLA $n — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n"; }
}
$_SESSION = [];
$UA_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 TukanIOS/1.0.0';
$UA_WEB = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

echo "1. Cómo se reconoce la app de iPhone\n";
$_SERVER['HTTP_USER_AGENT'] = $UA_WEB; $_COOKIE = [];
check('Safari del iPhone (sin TukanIOS) no es la app', false, en_app_ios());
check('… ni app de tienda', false, en_app_tienda());
$_SERVER['HTTP_USER_AGENT'] = $UA_IOS;
check('Con «TukanIOS» en el User-Agent sí es la app', true, en_app_ios());
check('… y cuenta como app de tienda', true, en_app_tienda());
$_SERVER['HTTP_USER_AGENT'] = $UA_WEB; $_COOKIE = ['canal' => 'ios'];
check('Con la cookie canal=ios (abrió con ?app=ios) también', true, en_app_ios());
$_COOKIE = [];
marcar_app_ios();
check('marcar_app_ios() deja la cookie canal=ios', 'ios', $_COOKIE['canal'] ?? null);
check('… y desde ahí es la app de iPhone', true, en_app_ios());

echo "2. Lo de Google Play nunca en el iPhone\n";
putenv('GOOGLE_PLAY_ACTIVO=1');
$_SERVER['HTTP_USER_AGENT'] = $UA_WEB; $_COOKIE = ['canal' => 'play'];
check('La app de Google Play sigue igual', true, en_app_play());
check('… es app de tienda', true, en_app_tienda());
check('… y no es iPhone', false, en_app_ios());
$_SERVER['HTTP_USER_AGENT'] = $UA_IOS; $_SESSION['app_play'] = true;
check('En el iPhone en_app_play() es falso aunque quede la marca de Google Play', false, en_app_play());
check('… pero sigue siendo app de tienda (sin precios de Tukán)', true, en_app_tienda());
putenv('GOOGLE_PLAY_ACTIVO=0'); $_SESSION = [];
$_SERVER['HTTP_USER_AGENT'] = $UA_IOS; $_COOKIE = [];
check('El iPhone no depende de que Google Play esté activo', true, en_app_ios());
check('El mensaje para acciones de pago en la app es neutro (no dice dónde pagar)', 0, preg_match('/pag|web|whatsapp|\$|usd/i', TEXTO_NO_EN_APP));

echo "\nResultado: $ok bien, $fallas fallas\n";
exit($fallas ? 1 : 0);
