<?php
declare(strict_types=1);

/**
 * Demos automáticas de 3 días — módulo común de la familia TuSalón (10 nov 2026). Pedido del dueño de Tukán: el interesado llena
 * TODOS sus datos, la clave le llega a su correo junto con el manual y la guía de primeros pasos, y la demo la crea solo el
 * Panel Tukán Central. El archivo es IGUAL en los cinco programas de la familia (si se cambia en uno, se copia a los demás).
 *
 * Qué trae:
 *   - validar(): los 6 datos obligatorios del pedido de demo, con el mensaje de cada campo (contrato de las demos automáticas).
 *   - avisar(): aviso al instante al Panel (demo_aviso.php, token v2 de tipo «demo» firmado con el secreto del Panel) para que
 *     la demo salga en segundos. Espera 5 segundos como máximo; si el Panel no contesta no pasa nada (lo verá en su revisión).
 *   - servirPdf(): /manual.pdf y /primeros_pasos.pdf públicos, desde los PDF de docs/.
 *   - descargas(): los dos botones de descarga (tarjeta de la demo en el inicio y Ayuda).
 */
final class TukanDemos
{
    /**
     * Los 6 datos obligatorios (10 nov 2026, 19:45, Dimitry: «con un teléfono es suficiente»): un solo teléfono, el celular.
     * Al Panel se le manda además «telefono» con el mismo valor del celular (DETALLE), por compatibilidad.
     */
    public const CAMPOS = ['nombre', 'nombre_comercial', 'celular', 'direccion', 'ciudad', 'email'];
    public const DETALLE = ['nombre', 'nombre_comercial', 'telefono', 'celular', 'direccion', 'ciudad', 'email'];
    public const GRACIAS = '¡Listo! Estamos preparando tu demo: toma entre 5 y 10 minutos aproximadamente. Cuando esté lista te llega al correo tu clave, el manual y la guía de primeros pasos. Revisa también la carpeta de spam.';
    public const AVISO_CORREO = 'Te enviaremos la clave de tu demo a este correo: escríbelo bien.';
    public const AVISO_DIAS = 'La demo dura 3 días.';
    public const AVISO_MINUTOS = 'Preparar tu demo toma entre 5 y 10 minutos aproximadamente.';
    /** (13 nov 2026, pedido de Dimitry) Aviso en la página de solicitud de demo: datos reales o no se prepara la demo. */
    public const AVISO_DATOS_REALES = 'Usa tus datos reales. Los necesitamos para activar tu demo y, después, tu plan. Si están incompletos o no son verdaderos, no podremos preparar tu demo.';

    /**
     * Limpia y revisa los 6 datos. Devuelve [datos limpios, errores (campo => mensaje)]. $negocio = cómo se llama el negocio en
     * este programa («spa», «salón»…), para el mensaje. El correo sale en minúsculas y sin espacios; los teléfonos, solo números y +.
     */
    public static function validar(array $d, string $negocio = 'negocio'): array
    {
        $t = fn(string $k, int $max) => mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_scalar($d[$k] ?? '') ? (string) ($d[$k] ?? '') : '')), 0, $max);
        $tel = fn(string $k) => (string) preg_replace('/[\s().-]+/', '', $t($k, 30));
        $r = ['nombre' => $t('nombre', 80), 'nombre_comercial' => $t('nombre_comercial', 120), 'celular' => $tel('celular'),
              'direccion' => $t('direccion', 200), 'ciudad' => $t('ciudad', 80), 'email' => strtolower((string) preg_replace('/\s+/u', '', $t('email', 120)))];
        $e = [];
        if (mb_strlen($r['nombre']) < 2) $e['nombre'] = 'Escribe tu nombre y apellido.';
        if (mb_strlen($r['nombre_comercial']) < 3) $e['nombre_comercial'] = 'Escribe el nombre de tu ' . $negocio . '.';
        $digitos = fn(string $v) => strlen((string) preg_replace('/\D/', '', $v));
        $c = $r['celular'];
        if (!preg_match('/^\+?\d+$/', $c) || $digitos($c) < 9 || $digitos($c) > 15) {
            $e['celular'] = 'Escribe tu celular: solo números (por ejemplo 0991234567).';
        } elseif ((str_starts_with($c, '0') && !preg_match('/^09\d{8}$/', $c)) || (preg_match('/^\+?593/', $c) && !preg_match('/^\+?5939\d{8}$/', $c))) {
            $e['celular'] = 'El celular de Ecuador tiene 10 números y empieza con 09 (o +5939…).';
        }
        if (mb_strlen($r['direccion']) < 5) $e['direccion'] = 'Escribe la dirección de tu ' . $negocio . '.';
        if (mb_strlen($r['ciudad']) < 2) $e['ciudad'] = 'Escribe tu ciudad.';
        if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'El correo no es válido. Revísalo: ahí te llega la clave de tu demo.';
        return [$r, $e];
    }

    /**
     * Avisa al Panel que hay un pedido de demo nuevo. $firmar = la función de firma que el programa ya usa con el Panel
     * (TukanCentral::firmar). Devuelve true si el Panel contestó bien. Nunca lanza errores: el pedido ya quedó guardado.
     * $config = ['id', 'secreto', 'url'] (por defecto TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET y TUKAN_CENTRAL_URL).
     */
    public static function avisar(string $solicitudId, callable $firmar, ?array $config = null, int $espera = 5): bool
    {
        try {
            $c = $config ?? ['id' => (string) getenv('TUKAN_CENTRAL_ID'), 'secreto' => (string) getenv('TUKAN_CENTRAL_SECRET'), 'url' => (string) getenv('TUKAN_CENTRAL_URL')];
            $id = trim((string) ($c['id'] ?? ''));
            $secreto = trim((string) ($c['secreto'] ?? ''));
            $url = rtrim(trim((string) ($c['url'] ?? '')), '/');
            if ($id === '' || strlen($secreto) < 32 || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) return false;   // sin conectar al Panel
            $cuerpo = (string) json_encode(['accion' => 'demo_pedida', 'id' => $solicitudId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ahora = time();
            $token = $firmar(['v' => 2, 'tipo' => 'demo', 'inst' => $id, 'email' => 'sistema@tukanec.com', 'nombre' => 'Pide tu demo', 'rol' => 'dueno',
                              'iat' => $ahora, 'exp' => $ahora + 60, 'nonce' => bin2hex(random_bytes(16)),
                              'bh' => rtrim(strtr(base64_encode(hash('sha256', $cuerpo, true)), '+/', '-_'), '=')], $secreto);
            $ch = curl_init($url . '/demo_aviso.php');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(3, $espera), CURLOPT_TIMEOUT => $espera,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Tukan-Token: ' . $token]]);
            $r = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            return $r !== false && $http >= 200 && $http < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Manda un PDF de docs/ (público, sin sesión) con un nombre claro. Si no existe: 404. Termina el pedido. */
    public static function servirPdf(string $archivo): never
    {
        if (!is_file($archivo) || !is_readable($archivo)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('No disponible.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($archivo) . '"');
        header('Content-Length: ' . filesize($archivo));
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($archivo);
        exit;
    }

    /** Los dos botones: «Descargar el manual» y «Descargar los primeros pasos» (el segundo solo si el programa tiene esa guía). */
    public static function botones(bool $hayPrimerosPasos = true): string
    {
        return '<div class="acciones"><a class="boton boton-chico" href="/manual.pdf" download>Descargar el manual</a>'
            . ($hayPrimerosPasos ? '<a class="boton boton-claro boton-chico" href="/primeros_pasos.pdf" download>Descargar los primeros pasos</a>' : '') . '</div>';
    }

    /** Tarjeta del inicio mientras la cuenta es demo: «Tu demo dura hasta el dd/mm. Descarga el manual y la guía de primeros pasos». */
    public static function tarjetaDemo(?string $hasta, bool $hayPrimerosPasos = true): string
    {
        $t = $hasta ? strtotime(substr($hasta, 0, 10)) : false;
        return '<section class="bloque" id="tarjeta-demo" role="status"><p style="margin:0 0 10px"><strong>' . ($t ? 'Tu demo dura hasta el ' . date('d/m', $t) . '.' : 'Estás en una demo.')
            . '</strong> Descarga el manual y la guía de primeros pasos.</p>' . self::botones($hayPrimerosPasos) . '</section>';
    }

    /** Bloque de Ayuda (para todos): manual y primeros pasos. $usted = trato de «usted». */
    public static function ayuda(bool $usted = false, bool $hayPrimerosPasos = true): string
    {
        return '<section class="bloque" id="descargas-ayuda"><h2>Manual y primeros pasos</h2><p>' . ($usted ? 'Descargue' : 'Descarga') . ' el manual completo y la guía de primeros pasos en PDF.</p>'
            . self::botones($hayPrimerosPasos) . '</section>';
    }
}
