<?php
declare(strict_types=1);

namespace TuSalon;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Conector con el Panel Tukán Central, protocolo v2 (PROTOCOLO_V2.md del Central; mapeo de TuSalón en §10.2 y §10.3).
 * Todas las acciones v2 son POST a /tukan_central.php?accion=<accion> con el token en la cabecera X-Tukan-Token
 * (mismo secreto y misma firma que v1, con "v":2 y "bh" = hash del cuerpo) y un cuerpo JSON.
 *
 * Lectura: capacidades, clientes, cliente, cotizar, planes, solicitudes, solicitud, finanzas.
 * Escritura: ejecutar (crear_demo, extender_prueba, registrar_pago, anular_pago, suspender, reactivar, cancelar,
 *            cambiar_plan, nueva_clave_dueno, nota_interna, atender_solicitud, guardar_plan,
 *            del Anexo A: pausar, borrar_cliente, restaurar_cliente — ver Papelera,
 *            y del Anexo B: cambiar_modulos, guardar_modulos — ver Modulos). Lectura del Anexo B: modulos.
 * Las reglas del negocio son las de TuSalón (Planes, PanelTukan, Altas); aquí solo se validan los datos,
 * se arma la respuesta y se garantiza que una operación repetida no se haga dos veces (tukan_central_operaciones).
 * Solicitudes: pedidos de demo (el formulario "Solicitar demo": ya no hay prueba gratis), pedidos de módulos desde
 * Mi plan (cambio_modulos), pagos automáticos de Google Play y los recordatorios que se arman al momento (demos por
 * terminar, renovaciones, dominios incluidos en el pago anual). TuSalón no tiene comprobantes ni soporte. Las "solicitudes" de reservas de cada salón son del salón, no de Tukán: no se mandan.
 */
final class TukanCentralV2
{
    public const PROTOCOLO = 2;
    public const REVISION = 3;   // 2: Anexo A (pausar, papelera) · 3: Anexo B (módulos) y pedidos de demo
    public const MAX_CUERPO = 65536;
    public const VIDA_LECTURA = 60;
    public const VIDA_EJECUTAR = 30;
    /** Acciones que existen en el protocolo v2 (el resto de nombres es "Acción no válida", 400). */
    public const ACCIONES_PROTOCOLO = ['capacidades', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar', 'modulos'];
    /** Las que TuSalón contesta (sin "adjunto": no tiene comprobantes ni tickets con archivos). */
    public const ACCIONES = ['capacidades', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'finanzas', 'ejecutar', 'modulos'];
    public const OPERACIONES = ['crear_demo', 'extender_prueba', 'registrar_pago', 'anular_pago', 'suspender', 'reactivar', 'cancelar',
                                'cambiar_plan', 'nueva_clave_dueno', 'nota_interna', 'atender_solicitud', 'guardar_plan',
                                'pausar', 'borrar_cliente', 'restaurar_cliente', 'cambiar_modulos', 'guardar_modulos'];
    /** Operaciones que se pueden pedir sobre un salón que está en la papelera. */
    public const OPERACIONES_PAPELERA = ['restaurar_cliente', 'borrar_cliente'];
    public const TIPOS_SOLICITUD = ['demo_pedida', 'cambio_modulos', 'pago_automatico', 'seguimiento_prueba', 'renovacion', 'dominio_gratis'];
    /** Estado propio de un pedido de demo -> estado genérico de la solicitud (§5.8). */
    public const ESTADOS_DEMO = ['nueva' => 'pendiente', 'contactada' => 'en_proceso', 'demo_creada' => 'atendida', 'descartada' => 'descartada'];
    public const COMISION_GOOGLE_PLAY = 0.15;   // la misma que usa GooglePlay::precioSugerido()
    public const ESTADOS = ['prueba', 'activo', 'por_vencer', 'vencido', 'suspendido'];
    public const ESTADOS_DETALLE = ['prueba' => 'Demo', 'activo' => 'Al día', 'sin_limite' => 'Sin vencimiento', 'gracia' => 'Venció (en gracia)',
                                    'vencido' => 'Vencido', 'prueba_vencida' => 'Demo terminada', 'suspendido' => 'Suspendido', 'cancelado' => 'Cancelado'];
    public const ORDENES = ['vence', 'alta', 'nombre', 'actividad'];
    public const RESULTADOS = ['en_proceso', 'atendida', 'descartada'];
    public const DIAS_POR_VENCER = 7;      // §4.2: pagado y vence en 0 a 7 días
    public const DIAS_PRUEBA_TERMINADA = 60;
    public const DIAS_RENOVACION = 5;
    public const DIAS_VENCIDA = 30;
    public const DIAS_PAGO_AUTOMATICO = 30;
    public const ID = '/^[A-Za-z0-9_.:-]{1,80}$/';

    private Planes $planes;
    private Modulos $modulos;
    private PanelTukan $panel;
    private TukanCentral $v1;
    private DateTimeImmutable $hoy;
    private ?array $pagosCache = null;
    private ?array $solicitudesCache = null;
    /** Quién hace la operación en curso: "Nombre <correo>" del token. */
    private ?string $quien = null;

    public function __construct(private PDO $db, ?DateTimeImmutable $hoy = null)
    {
        $this->hoy = ($hoy ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $this->planes = new Planes($db);
        $this->modulos = new Modulos($db);
        $this->panel = new PanelTukan($db);
        $this->v1 = new TukanCentral($db);
    }

    // ------------------------------------------------------------------
    // Transporte: validación del pedido (§3.1) y respuesta
    // ------------------------------------------------------------------

    /**
     * Atiende un pedido v2 completo (lo usa public/tukan_central.php y las pruebas).
     * Devuelve [http, json (array), cabeceras extra].
     */
    public function procesar(string $accion, string $metodo, string $tipoContenido, string $cuerpo, string $token, string $ip, ?int $ahora = null): array
    {
        try {
            if (!TukanCentral::config()) {
                throw new TukanCentralError('sin_configurar', 'El conector con el Panel Tukán Central no está configurado.', 404);
            }
            if (!$this->v1->dentroDelLimite($ip, $ahora)) {
                throw new TukanCentralError('limite', 'Demasiados pedidos. Espera un minuto y vuelve a intentar.', 429);
            }
            if (!in_array($accion, self::ACCIONES_PROTOCOLO, true)) throw new TukanCentralError('invalido', 'Acción no válida.', 400, 'accion');
            [$p, $datos] = $this->validar($accion, $metodo, $tipoContenido, $cuerpo, $token, $ahora);
            [$http, $json] = $this->atender($accion, $p, $datos, self::hashCuerpo($cuerpo));
            return [$http, $json, []];
        } catch (TukanCentralError $e) {
            if ($e->http === 401) {
                try { $this->v1->anotarRechazo($ip, $ahora); } catch (Throwable) { /* el límite no debe tapar el error */ }
            }
            $cab = match (true) {
                $e->codigo === 'limite' => ['Retry-After' => '60'],
                $e->http === 405 => ['Allow' => 'POST'],
                default => [],
            };
            return [$e->http, $e->json(), $cab];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('TuSalón central v2: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            return [500, (new TukanCentralError('interno', 'TuSalón tuvo un error interno. Intenta en un momento.', 500))->json(), []];
        }
    }

    public static function hashCuerpo(string $cuerpo): string
    {
        return TukanCentral::b64(hash('sha256', $cuerpo, true));
    }

    /** Revisa el pedido en el orden del protocolo (§3.1, pasos 3 a 15). Devuelve [payload del token, datos del cuerpo]. */
    public function validar(string $accion, string $metodo, string $tipoContenido, string $cuerpo, string $token, ?int $ahora = null): array
    {
        $conf = TukanCentral::config();
        if (!$conf) throw new TukanCentralError('sin_configurar', 'El conector con el Panel Tukán Central no está configurado.', 404);
        $ahora ??= time();
        // 3. Método, tipo de contenido y tamaño
        if (strtoupper($metodo) !== 'POST') throw new TukanCentralError('invalido', 'Esta acción se pide con POST.', 405);
        if (!preg_match('#^application/json\s*(;|$)#i', trim($tipoContenido))) {
            throw new TukanCentralError('invalido', 'El contenido debe ser JSON (Content-Type: application/json).', 415);
        }
        if (strlen($cuerpo) > self::MAX_CUERPO) throw new TukanCentralError('invalido', 'El pedido es demasiado grande (máximo 64 KiB).', 413);
        // 4. Firma
        $partes = explode('.', $token);
        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === ''
            || !hash_equals(TukanCentral::b64(hash_hmac('sha256', $partes[0], $conf['secreto'], true)), $partes[1])) {
            throw new TukanCentralError('firma', 'La firma del pedido no es correcta.', 401);
        }
        $json = TukanCentral::unb64($partes[0]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p)) throw new TukanCentralError('firma', 'El pedido no es válido.', 401);
        // 5 a 7. Versión, tipo e instancia
        if (($p['v'] ?? null) !== 2) throw new TukanCentralError('version', 'Versión de protocolo no soportada.', 401);
        if (($p['tipo'] ?? null) !== $accion) throw new TukanCentralError('tipo', 'El pedido no es para esta acción.', 401);
        if (!is_string($p['inst'] ?? null) || !hash_equals($conf['id'], $p['inst'])) throw new TukanCentralError('inst', 'El pedido es de otra instalación.', 401);
        // 8 a 10. Tiempo
        $iat = $p['iat'] ?? null;
        $exp = $p['exp'] ?? null;
        $max = $accion === 'ejecutar' ? self::VIDA_EJECUTAR : self::VIDA_LECTURA;
        if (!is_int($iat) || !is_int($exp) || $exp < $iat || $exp - $iat > $max) {
            throw new TukanCentralError('invalido', 'El pedido dura más de lo permitido.', 401);
        }
        if ($ahora > $exp + TukanCentral::TOLERANCIA) throw new TukanCentralError('vencido', 'El pedido venció. Intente otra vez desde el Panel Tukán.', 401);
        if ($iat > $ahora + TukanCentral::TOLERANCIA) throw new TukanCentralError('futuro', 'El pedido tiene fecha futura (revise la hora del servidor).', 401);
        // 11. Hash del cuerpo
        if (!is_string($p['bh'] ?? null) || !hash_equals(self::hashCuerpo($cuerpo), $p['bh'])) {
            throw new TukanCentralError('firma', 'El contenido del pedido cambió en el camino.', 401, null, ['parte' => 'cuerpo']);
        }
        // 12. Nonce de un solo uso (después de firma, tiempo y cuerpo: un token roto no gasta su nonce)
        if (!$this->v1->usarNonce($p['nonce'] ?? null, $exp, $ahora)) {
            throw new TukanCentralError('nonce', 'El pedido se repitió (nonce ya usado o no válido).', 401);
        }
        // 13. Correo
        $email = $p['email'] ?? null;
        if (!is_string($email) || mb_strlen($email) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new TukanCentralError('invalido', 'Correo inválido en el pedido.', 401);
        }
        $p['email'] = strtolower(trim($email));
        $p['nombre'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_string($p['nombre'] ?? null) ? $p['nombre'] : '')), 0, 100);
        // 14. Cuerpo: objeto JSON
        $datos = json_decode($cuerpo, true, 8);
        if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) throw new TukanCentralError('invalido', 'El contenido no es un objeto JSON.', 400);
        // 15. Acción disponible en TuSalón
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new TukanCentralError('no_permitido', 'TuSalón no tiene esta acción.', 403, null, ['motivo' => 'no_disponible']);
        }
        return [$p, $datos];
    }

    private function atender(string $accion, array $p, array $d, string $bh): array
    {
        if ($accion === 'ejecutar') return $this->ejecutar($p, $d, $bh);
        $r = match ($accion) {
            'capacidades' => $this->capacidades(),
            'clientes' => $this->clientes($d),
            'cliente' => ['cliente' => $this->clienteFicha($this->idCliente($d['id'] ?? null, 'id'))],
            'cotizar' => $this->cotizar($d),
            'planes' => $this->planesV2(),
            'solicitudes' => $this->solicitudes($d),
            'solicitud' => ['solicitud' => $this->solicitud($d)],
            'finanzas' => $this->finanzas($d),
            'modulos' => $this->modulosV2(),
        };
        return [200, $this->sobre($accion) + $r];
    }

    private function sobre(string $accion): array
    {
        return ['ok' => true, 'protocolo' => self::PROTOCOLO, 'accion' => $accion, 'producto' => TukanCentral::PRODUCTO,
                'generado' => (new DateTimeImmutable('now'))->format('c')];
    }

    // ------------------------------------------------------------------
    // capacidades (§5.1)
    // ------------------------------------------------------------------

    public function capacidades(): array
    {
        $periodos = [];
        foreach ($this->planes->periodos() as $per => $x) $periodos[] = ['periodo' => $per, 'meses' => $x['recibe']];
        $metodos = [];
        foreach (PanelTukan::METODOS as $m => $nombre) $metodos[] = ['metodo' => $m, 'nombre' => $nombre];
        return [
            'revision' => self::REVISION,
            'nombre' => TukanCentral::NOMBRE,
            'version' => TukanCentral::version(dirname(__DIR__)),
            'unico' => false,
            'moneda' => 'USD',
            'zona_horaria' => 'America/Guayaquil',
            'url_publica' => self::urlBase(),
            'acciones' => array_merge(['capacidades', 'estado', 'sso'], array_values(array_diff(self::ACCIONES, ['capacidades']))),
            'sso_ir' => true,
            'rutas_sso' => TukanCentral::RUTAS_SSO,
            'ejecutar' => self::OPERACIONES,
            'tipos_solicitud' => self::TIPOS_SOLICITUD,
            'estados_detalle' => self::ESTADOS_DETALLE,
            'periodos' => $periodos,
            'meses_libres' => false,
            'metodos_pago' => $metodos,
            'planes_editar' => true,
            'filtros_clientes' => ['buscar', 'estados', 'plan', 'vence_desde', 'vence_hasta', 'alta_desde', 'alta_hasta', 'sin_uso_dias', 'origen', 'ids', 'papelera'],
            'tipos_pausa' => Papelera::TIPOS_PAUSA,
            'papelera' => ['dias' => Papelera::DIAS_PAPELERA, 'copia_dias' => Papelera::DIAS_COPIA],
            'formularios' => [
                'pausar' => self::camposPausa(),
                'borrar_cliente' => self::camposBorrar(null),
                'crear_demo' => [self::campoModulosDemo()],   // además de los comunes (el plan va en los comunes)
                'guardar_plan' => $this->camposPlan(null),
                'cambiar_modulos' => $this->camposModulos(null),
            ],
            'limites' => ['por_pagina_max' => 200, 'meses_finanzas_max' => 24, 'dias_prueba_max' => 90],
        ];
    }

    // ------------------------------------------------------------------
    // Clientes (§4.2, §4.3, §5.4, §5.5)
    // ------------------------------------------------------------------

    /** Todos los salones con su estado de cuenta (Planes::estadoCuenta), por id. */
    private function filas(): array
    {
        $r = [];
        foreach ($this->panel->salones('', '', $this->hoy) as $n) $r[(int) $n['id']] = $n;
        return $r;
    }

    private function fila(int $id): ?array
    {
        return $this->panel->salon($id, $this->hoy);
    }

    /** Estado v2 con la regla única de §4.2 (a partir del estado propio de TuSalón). */
    public function clasificar(array $n): array
    {
        $nativo = $n['estado_cuenta'] ?? $this->planes->estadoCuenta($n, $this->hoy);
        $en7 = $this->hoy->modify('+' . self::DIAS_POR_VENCER . ' days')->format('Y-m-d');
        [$estado, $detalle, $gracia] = match ($nativo) {
            'prueba' => ['prueba', 'prueba', false],
            'prueba_vencida' => ['vencido', 'prueba_vencida', false],
            'activo' => empty($n['activo_hasta']) ? ['activo', 'sin_limite', false]
                        : [$n['activo_hasta'] <= $en7 ? 'por_vencer' : 'activo', 'activo', false],
            'por_vencer' => ['vencido', 'gracia', true],   // en TuSalón "por_vencer" es "ya venció, en días de gracia"
            'vencido' => ['vencido', 'vencido', false],
            'suspendido' => ['suspendido', 'suspendido', false],
            default => ['suspendido', 'cancelado', false],
        };
        return ['estado' => $estado, 'estado_detalle' => $detalle, 'en_gracia' => $gracia,
                'bloqueado' => !in_array($nativo, ['prueba', 'activo', 'por_vencer'], true), 'nativo' => $nativo];
    }

    /** Cuántos clientes hay en cada estado v2 (lo usan "clientes" y el "estado" v1, para que den lo mismo). */
    public function contadores(?array $todos = null): array
    {
        $c = ['total' => 0, 'prueba' => 0, 'activo' => 0, 'por_vencer' => 0, 'vencido' => 0, 'suspendido' => 0];
        foreach ($todos ?? $this->filas() as $n) {
            $c['total']++;
            $c[$this->clasificar($n)['estado']]++;
        }
        return $c;
    }

    /** Pagos de suscripción no anulados de cada salón (total, cantidad, primero, último y lo que vale al mes lo vigente). */
    private function pagosResumen(): array
    {
        if ($this->pagosCache !== null) return $this->pagosCache;
        $hoy = $this->hoy->format('Y-m-d');
        $st = $this->db->prepare('SELECT salon_id, count(*) AS cantidad, COALESCE(sum(monto), 0) AS total, min(pagado_en) AS primer, max(pagado_en) AS ultimo,
                                         (array_agg(monto ORDER BY pagado_en DESC, id DESC))[1] AS ultimo_monto,
                                         COALESCE(sum(CASE WHEN inicio <= ?::date AND fin >= ?::date THEN monto / meses_recibidos ELSE 0 END), 0) AS mensual,
                                         count(*) FILTER (WHERE inicio <= ?::date AND fin >= ?::date AND monto > 0) AS vigentes
                                    FROM suscripciones WHERE NOT anulada GROUP BY salon_id');
        $st->execute([$hoy, $hoy, $hoy, $hoy]);
        $r = [];
        foreach ($st->fetchAll() as $f) $r[(int) $f['salon_id']] = $f;
        return $this->pagosCache = $r;
    }

    private function olvidar(): void
    {
        $this->pagosCache = null;
        $this->solicitudesCache = null;
    }

    /** Cliente con la forma de §4.3. */
    private function cliente(array $n): array
    {
        $c = $this->clasificar($n);
        $pg = $this->pagosResumen()[(int) $n['id']] ?? null;
        $vence = ($n['estado'] === 'prueba' ? $n['prueba_hasta'] : $n['activo_hasta']) ?: null;
        $dias = $vence ? $this->diasHasta($vence) : null;
        $tel = $n['telefono'] ?: null;   // TuSalón solo tiene salones.telefono (el del dueño)
        return [
            'id' => (string) $n['id'],
            'nombre' => (string) $n['nombre'],
            'tipo' => null,
            'tipo_nombre' => null,
            'ciudad' => null,
            'plan' => ['codigo' => (string) $n['plan_codigo'], 'nombre' => (string) $n['plan_nombre']],
            'estado' => $c['estado'],
            'estado_detalle' => $c['estado_detalle'],
            'estado_texto' => $this->textoEstado($c, $n, $vence, $dias),
            'en_gracia' => $c['en_gracia'],
            'bloqueado' => $c['bloqueado'],
            'fechas' => [
                'alta' => substr((string) $n['creado_en'], 0, 10),
                'prueba_hasta' => $n['prueba_hasta'] ?: null,
                'activo_hasta' => $n['activo_hasta'] ?: null,
                'vence' => $vence,
                'dias_para_vencer' => $dias,
            ],
            'dueno' => ['nombre' => $n['dueno'] ?: null, 'telefono' => $tel ?: null, 'whatsapp' => WhatsApp::normalizarTelefono($tel),
                        'email' => $n['dueno_email'] ?: null],
            'ultima_actividad' => self::momento($n['ultima_actividad'] ?? null),
            'pagos' => [
                'total' => round((float) ($pg['total'] ?? 0), 2),
                'cantidad' => (int) ($pg['cantidad'] ?? 0),
                'primer_pago' => $pg['primer'] ?? null,
                'ultimo_pago' => $pg['ultimo'] ?? null,
                'ultimo_monto' => $pg ? round((float) $pg['ultimo_monto'], 2) : null,
            ],
            // Anexo B: lo que vale al mes = plan + módulos extra − quitados (o el precio acordado), si está pagando
            'mensual_estimado' => (int) ($pg['vigentes'] ?? 0) > 0 ? $this->detalleModulos($n)['mensual'] : 0.0,
            'modulos' => $this->modulosCliente($n),
            'origen' => in_array($n['origen'] ?? '', Altas::ORIGENES, true) ? $n['origen'] : 'desconocido',
            'vendedor' => null,
            'uso' => ['Peluqueros' => (int) $n['peluqueros'], 'Citas (30 días)' => (int) $n['citas_30'], 'Clientes' => (int) $n['clientes']],
            'ir' => '/?r=admin_salon&id=' . (int) $n['id'],
            'ir_trabajar' => null,
            'actualizado' => (new DateTimeImmutable('now'))->format('c'),
            // Anexo A: pausa y papelera
            'pausa' => $n['estado'] === 'suspendido' ? [
                'tipo' => $n['pausa_tipo'] ?? null, 'tipo_nombre' => Papelera::TIPOS_PAUSA[$n['pausa_tipo'] ?? ''] ?? 'Suspendido',
                'motivo' => $n['pausa_motivo'] ?? null, 'desde' => self::momento($n['pausa_en'] ?? null),
                'texto_cliente' => Papelera::textoPausa($n['pausa_tipo'] ?? null, $n['pausa_motivo'] ?? null)] : null,
            'eliminado' => !empty($n['eliminado_en']),
            'papelera' => !empty($n['eliminado_en']) ? [
                'desde' => self::momento($n['eliminado_en']), 'hasta' => Papelera::fechaPurga($n['eliminado_en']),
                'motivo' => $n['eliminado_motivo'] ?? null, 'por' => $n['eliminado_por'] ?? null,
                'se_puede_restaurar' => Papelera::fechaPurga($n['eliminado_en']) > $this->hoy->format('Y-m-d')] : null,
        ];
    }

    private function textoEstado(array $c, array $n, ?string $vence, ?int $dias): string
    {
        return match ($c['estado_detalle']) {
            'prueba' => 'Demo hasta el ' . self::fechaTexto($vence) . ($dias === 0 ? ' (termina hoy)' : ' (faltan ' . self::dias((int) $dias) . ')'),
            'sin_limite' => 'Al día, sin fecha de vencimiento',
            'activo' => 'Al día hasta el ' . self::fechaTexto($vence)
                        . ($c['estado'] === 'por_vencer' ? ($dias === 0 ? ' (vence hoy)' : ' (vence en ' . self::dias((int) $dias) . ')') : ''),
            'gracia' => 'Venció el ' . self::fechaTexto($n['activo_hasta']) . ' (en días de gracia, todavía puede trabajar)',
            'vencido' => 'Venció el ' . self::fechaTexto($n['activo_hasta']),
            'prueba_vencida' => 'La demo terminó el ' . self::fechaTexto($n['prueba_hasta']),
            'suspendido' => !empty($n['pausa_tipo']) ? 'Pausado por Tukán: ' . (Papelera::TIPOS_PAUSA[$n['pausa_tipo']] ?? $n['pausa_tipo']) : 'Suspendido por Tukán',
            default => 'Cancelado',
        } . (!empty($n['eliminado_en']) ? ' · En la papelera (se borra el ' . self::fechaTexto(Papelera::fechaPurga($n['eliminado_en'])) . ')' : '');
    }

    public function clientes(array $d): array
    {
        $pagina = $this->entero($d, 'pagina', 1, 1, 1000000);
        $porPagina = $this->entero($d, 'por_pagina', 50, 1, 200);
        $buscar = $this->texto($d, 'buscar', 80);
        // Papelera (Anexo A): filtros.estado = "papelera" (también "estado" o "papelera" en "estados")
        $filtros = $d['filtros'] ?? [];
        if (!is_array($filtros)) throw TukanCentralError::campo('filtros', 'Los filtros no son válidos.');
        $papelera = ($filtros['estado'] ?? null) === 'papelera' || ($d['estado'] ?? null) === 'papelera'
                    || (is_array($d['estados'] ?? null) && in_array('papelera', $d['estados'], true));
        if (is_array($d['estados'] ?? null)) $d['estados'] = array_values(array_filter($d['estados'], fn($x) => $x !== 'papelera'));
        $estados = $this->lista($d, 'estados', self::ESTADOS);
        $plan = $this->texto($d, 'plan', 40);
        $tipo = $this->texto($d, 'tipo', 40);
        $origen = $this->texto($d, 'origen', 20);
        $venceDesde = $this->fecha($d, 'vence_desde');
        $venceHasta = $this->fecha($d, 'vence_hasta');
        $altaDesde = $this->fecha($d, 'alta_desde');
        $altaHasta = $this->fecha($d, 'alta_hasta');
        $sinUso = ($d['sin_uso_dias'] ?? null) === null ? null : $this->entero($d, 'sin_uso_dias', 0, 0, 3650);
        $ids = $d['ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500) throw TukanCentralError::campo('ids', 'La lista de clientes no es válida.');
        $ids = array_map(fn($x) => is_int($x) || (is_string($x) && preg_match(self::ID, $x)) ? (string) $x
            : throw TukanCentralError::campo('ids', 'La lista de clientes no es válida.'), $ids);
        $orden = $this->opcion($d, 'orden', self::ORDENES, 'vence');

        $todos = $this->filas();
        $contadores = $this->contadores($todos);
        $enPapelera = $this->panel->salones('', '', $this->hoy, true);
        $base = $papelera ? $enPapelera : $todos;
        if ($buscar !== '') {
            $base = [];
            foreach ($this->panel->salones('', $buscar, $this->hoy, $papelera) as $n) $base[(int) $n['id']] = $n;
        }
        $limiteUso = $sinUso === null ? null : (new DateTimeImmutable('now'))->modify("-$sinUso days")->format('Y-m-d H:i:s');
        $lista = [];
        foreach ($base as $n) {
            $c = $this->cliente($n);
            if ($estados && !in_array($c['estado'], $estados, true)) continue;
            if ($plan !== '' && $c['plan']['codigo'] !== $plan) continue;
            if ($tipo !== '' && $c['tipo'] !== $tipo) continue;
            if ($origen !== '' && $c['origen'] !== $origen) continue;
            if ($ids && !in_array($c['id'], $ids, true)) continue;
            $v = $c['fechas']['vence'];
            if ($venceDesde !== null && ($v === null || $v < $venceDesde)) continue;
            if ($venceHasta !== null && ($v === null || $v > $venceHasta)) continue;
            if ($altaDesde !== null && $c['fechas']['alta'] < $altaDesde) continue;
            if ($altaHasta !== null && $c['fechas']['alta'] > $altaHasta) continue;
            if ($limiteUso !== null && $n['ultima_actividad'] !== null && (string) $n['ultima_actividad'] >= $limiteUso) continue;
            $c['_act'] = (string) ($n['ultima_actividad'] ?? '');
            $lista[] = $c;
        }
        usort($lista, match ($orden) {
            'alta' => fn($a, $b) => [$b['fechas']['alta'], (int) $b['id']] <=> [$a['fechas']['alta'], (int) $a['id']],
            'nombre' => fn($a, $b) => [mb_strtolower($a['nombre']), (int) $a['id']] <=> [mb_strtolower($b['nombre']), (int) $b['id']],
            'actividad' => fn($a, $b) => [$a['_act'] !== '', $a['_act'], (int) $a['id']] <=> [$b['_act'] !== '', $b['_act'], (int) $b['id']],
            default => fn($a, $b) => [$a['fechas']['vence'] === null, $a['fechas']['vence'], mb_strtolower($a['nombre'])]
                                     <=> [$b['fechas']['vence'] === null, $b['fechas']['vence'], mb_strtolower($b['nombre'])],
        });
        $total = count($lista);
        $pagina_ = array_map(function ($c) { unset($c['_act']); return $c; }, array_slice($lista, ($pagina - 1) * $porPagina, $porPagina));
        return ['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => max(1, (int) ceil($total / $porPagina)),
                'contadores' => $contadores, 'papelera' => $papelera, 'papelera_total' => count($enPapelera), 'clientes' => $pagina_];
    }

    public function clienteFicha(int $id): array
    {
        $n = $this->fila($id);
        if (!$n) throw new TukanCentralError('no_encontrado', 'Ese salón ya no existe en TuSalón.', 404);
        $c = $this->cliente($n);
        $st = $this->db->prepare('SELECT dominio FROM suscripciones WHERE salon_id = ? AND dominio IS NOT NULL AND NOT anulada ORDER BY fin DESC, id DESC LIMIT 1');
        $st->execute([$id]);
        $dominio = (string) ($st->fetchColumn() ?: '');
        $pagos = [];
        foreach ($this->panel->pagos($id, 200) as $s) {
            $anulado = (bool) $s['anulada'];
            $pagos[] = [
                'id' => (string) $s['id'], 'fecha' => (string) $s['pagado_en'], 'monto' => round((float) $s['monto'], 2),
                'plan' => ['codigo' => (string) $s['plan_codigo'], 'nombre' => (string) $s['plan_nombre']],
                'periodo' => (string) $s['periodo'], 'meses' => (int) $s['meses_recibidos'],
                'desde' => (string) $s['inicio'], 'hasta' => (string) $s['fin'],
                'metodo' => $s['metodo_pago'], 'metodo_nombre' => self::nombreMetodo((string) $s['metodo_pago']),
                'nota' => null, 'registrado_por' => $s['metodo_pago'] === 'google_play' ? 'Google Play (automático)' : null,
                'origen' => $s['metodo_pago'] === 'google_play' ? 'google_play' : 'panel', 'anulado' => $anulado, 'puede_anular' => !$anulado,
            ];
        }
        $st = $this->db->prepare('SELECT r.creado_en, r.accion, a.nombre, a.email FROM registro_admin r LEFT JOIN superadmins a ON a.id = r.admin_id
                                   WHERE r.salon_id = ? ORDER BY r.creado_en DESC, r.id DESC LIMIT 20');
        $st->execute([$id]);
        $registro = array_map(fn($r) => [
            'momento' => self::momento($r['creado_en']),
            'quien' => str_starts_with((string) $r['accion'], 'Panel Tukán Central')
                ? 'Panel Tukán Central · ' . ($r['nombre'] ?? '') . ' <' . ($r['email'] ?? '') . '>' : ($r['nombre'] ?? 'Sistema'),
            'texto' => (string) $r['accion'],
        ], $st->fetchAll());
        $abiertas = array_values(array_filter($this->todasLasSolicitudes(),
            fn($s) => $s['cliente_id'] === (string) $id && in_array($s['estado'], ['pendiente', 'en_proceso'], true)));
        return $c + [
            'notas_admin' => (string) ($n['notas_admin'] ?? ''),
            'usuarios' => (int) $this->db->query('SELECT count(*) FROM usuarios WHERE activo AND salon_id = ' . (int) $id)->fetchColumn(),
            'recomendado_por' => null,
            'combo_con' => null,
            'dominio' => $dominio,
            'enlace_publico' => self::urlSitio((string) $n['slug']),
            'pagos_detalle' => $pagos,
            'solicitudes' => $abiertas,
            'registro' => $registro,
            'mensajes_sugeridos' => $this->mensajes($n),
            'acciones_posibles' => $this->accionesCliente($n, $c),
        ];
    }

    /** Textos de WhatsApp de TuSalón (los mismos del panel de Tukán), o null si no aplica. */
    private function mensajes(array $n): array
    {
        $c = $this->clasificar($n);
        $primer = trim(explode(' ', trim((string) ($n['dueno'] ?: '')))[0]) ?: 'hola';
        $hola = $primer === 'hola' ? 'Hola' : "Hola $primer";
        $nombre = (string) $n['nombre'];
        $precio = self::dinero((float) ($this->db->query('SELECT precio_mensual FROM planes WHERE codigo = ' . $this->db->quote((string) $n['plan_codigo']))->fetchColumn() ?: 0));
        $nat = $c['nativo'];
        return [
            'bienvenida' => "$hola, te escribo de TuSalón. La cuenta de $nombre ya está lista: entra en " . self::urlLogin()
                . ' con tu correo' . ($n['dueno_email'] ? ' ' . $n['dueno_email'] : '') . '. ¿Te ayudo a empezar?',
            'recordar_prueba' => $nat === 'prueba' && $n['prueba_hasta']
                ? "$hola, soy de TuSalón. Tu demo de $nombre " . ($this->diasHasta($n['prueba_hasta']) === 0 ? 'termina hoy' : 'termina el ' . self::fechaCorta($n['prueba_hasta']))
                  . '. ¿Te ayudo a elegir tu plan para que sigas usándolo?' : null,
            'recordar_renovacion' => $nat === 'activo' && $n['activo_hasta']
                ? "$hola, soy de TuSalón. El plan de $nombre vence el " . self::fechaCorta($n['activo_hasta'])
                  . " (plan {$n['plan_nombre']}: $precio al mes). ¿Te paso los datos para renovarlo?" : null,
            'vencido' => in_array($nat, ['prueba_vencida', 'por_vencer', 'vencido'], true)
                ? "$hola, soy de TuSalón. Vi que $nombre ya no tiene el plan activo. Tus datos siguen guardados. ¿Te ayudo a renovarlo?" : null,
        ];
    }

    private function puedeExtender(array $c): bool
    {
        return $c['estado'] === 'prueba' || ($c['estado'] === 'vencido' && $c['pagos']['cantidad'] === 0);
    }

    // --- Formularios genéricos (§4.4) ---

    private function accionesCliente(array $n, array $c): array
    {
        $datos = ['cliente_id' => $c['id']];
        $m = $this->mensajes($n);
        $a = [];
        if ($c['eliminado']) {   // en la papelera: solo restaurar (mientras se pueda) y ver
            if ($c['papelera']['se_puede_restaurar']) {
                $a[] = ['accion' => 'restaurar_cliente', 'etiqueta' => 'Restaurar', 'estilo' => 'principal',
                        'confirmar' => '¿Sacar a ' . $c['nombre'] . ' de la papelera? Vuelve tal como estaba.', 'datos' => $datos, 'campos' => []];
            }
            $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuSalón', 'ir' => $c['ir']];
            return $a;
        }
        if ($c['dueno']['whatsapp']) {
            $texto = $m['vencido'] ?? $m['recordar_prueba'] ?? $m['recordar_renovacion'] ?? $m['bienvenida'];
            $a[] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $c['dueno']['whatsapp'], 'texto' => $texto];
        }
        $a[] = $this->formPago($n, $datos);
        if ($this->puedeExtender($c)) $a[] = $this->formExtender($c, $datos);
        $a[] = ['accion' => 'cambiar_modulos', 'etiqueta' => 'Módulos', 'estilo' => 'normal',
                'confirmar' => '¿Guardar los módulos de ' . $c['nombre'] . '? Apagar un módulo no borra sus datos.', 'datos' => $datos,
                'campos' => $this->camposModulos($n)];
        $a[] = ['accion' => 'cambiar_plan', 'etiqueta' => 'Cambiar plan', 'estilo' => 'normal', 'confirmar' => '¿Cambiar el plan de ' . $c['nombre'] . ' sin cobrar?',
                'datos' => $datos, 'campos' => [['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => $c['plan']['codigo'],
                                                 'opciones' => $this->opcionesPlan()]]];
        if (in_array($c['estado_detalle'], ['suspendido', 'cancelado'], true)) {
            $a[] = ['accion' => 'reactivar', 'etiqueta' => 'Reactivar', 'estilo' => 'principal', 'confirmar' => '¿Reactivar ' . $c['nombre'] . '?', 'datos' => $datos, 'campos' => []];
        } else {
            $a[] = ['accion' => 'pausar', 'etiqueta' => 'Pausar', 'estilo' => 'peligro',
                    'confirmar' => '¿Pausar ' . $c['nombre'] . '? No podrá trabajar y su página de reservas dirá "temporalmente no disponible" hasta que lo reactives.',
                    'datos' => $datos, 'campos' => self::camposPausa()];
        }
        if ($c['estado_detalle'] !== 'cancelado') {
            $a[] = ['accion' => 'cancelar', 'etiqueta' => 'Cancelar cuenta', 'estilo' => 'peligro', 'confirmar' => '¿Cancelar la cuenta de ' . $c['nombre'] . '?',
                    'datos' => $datos, 'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => true, 'min' => 5, 'max' => 200]]];
        }
        if ($n['dueno_usuario_id']) {
            $a[] = ['accion' => 'nueva_clave_dueno', 'etiqueta' => 'Nueva clave del dueño', 'estilo' => 'normal',
                    'confirmar' => '¿Crear una contraseña nueva para el dueño? La anterior deja de servir.', 'datos' => $datos, 'campos' => []];
        }
        $a[] = ['accion' => 'nota_interna', 'etiqueta' => 'Nota interna', 'estilo' => 'normal', 'confirmar' => null, 'datos' => $datos,
                'campos' => [['campo' => 'texto', 'etiqueta' => 'Notas de Tukán (el cliente no las ve)', 'tipo' => 'texto_largo', 'requerido' => false,
                              'valor' => (string) ($n['notas_admin'] ?? ''), 'max' => 4000]]];
        $a[] = ['accion' => 'borrar_cliente', 'etiqueta' => 'Borrar', 'estilo' => 'peligro',
                'confirmar' => '¿Borrar ' . $c['nombre'] . '? Queda ' . Papelera::DIAS_PAPELERA . ' días en la papelera (se puede restaurar) y después se borra para siempre.',
                'datos' => $datos, 'campos' => self::camposBorrar($c['nombre'])];
        $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuSalón', 'ir' => $c['ir']];
        return $a;
    }

    /** Campos de "pausar" (Anexo A): tipo y motivo obligatorios. */
    public static function camposPausa(): array
    {
        $tipos = [];
        foreach (Papelera::TIPOS_PAUSA as $k => $v) $tipos[] = ['valor' => $k, 'etiqueta' => $v];
        return [
            ['campo' => 'tipo', 'etiqueta' => 'Tipo', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'falta_pago', 'opciones' => $tipos],
            ['campo' => 'motivo', 'etiqueta' => 'Motivo (el salón lo ve, salvo en revisión y uso indebido)', 'tipo' => 'texto', 'requerido' => true, 'min' => 3, 'max' => 300],
        ];
    }

    /** Campos de "borrar_cliente" (Anexo A): escribir el nombre exacto y el motivo. */
    public static function camposBorrar(?string $nombre): array
    {
        return [
            ['campo' => 'confirmar', 'etiqueta' => 'Escribe el nombre exacto del salón', 'tipo' => 'texto', 'requerido' => true, 'max' => 120,
             'ayuda' => $nombre !== null ? 'Escribe: ' . $nombre : 'El nombre exacto (o el número) del salón.'],
            ['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => true, 'min' => 3, 'max' => 300],
        ];
    }

    private function opcionesPlan(): array
    {
        return array_map(fn($p) => ['valor' => (string) $p['codigo'], 'etiqueta' => $p['nombre'] . ' (' . self::dinero((float) $p['precio_mensual']) . ' al mes)'],
                         $this->planes->lista());
    }

    private function formPago(array $n, array $datos): array
    {
        $periodos = [];
        foreach ($this->planes->periodos() as $per => $x) {
            $periodos[] = ['valor' => $per, 'etiqueta' => $per === 'mensual' ? '1 mes'
                : $x['recibe'] . ' meses (paga ' . $x['paga'] . ($x['dominio'] ? ', con dominio propio' : '') . ')'];
        }
        $metodos = [];
        foreach (PanelTukan::METODOS as $m => $nombre) $metodos[] = ['valor' => $m, 'etiqueta' => $nombre];
        $cot = $this->planes->cotizar((string) $n['plan_codigo'], 'mensual', (int) $n['id']);   // con sus módulos (Anexo B)
        return ['accion' => 'registrar_pago', 'etiqueta' => 'Registrar pago', 'estilo' => 'principal',
                'confirmar' => '¿Registrar el pago de ' . $n['nombre'] . '?', 'datos' => $datos,
                'campos' => [
                    ['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => (string) $n['plan_codigo'], 'opciones' => $this->opcionesPlan()],
                    ['campo' => 'periodo', 'etiqueta' => 'Tiempo', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'mensual', 'opciones' => $periodos],
                    ['campo' => 'monto', 'etiqueta' => 'Valor recibido ($)', 'tipo' => 'dinero', 'requerido' => true, 'valor' => $cot['monto'], 'min' => 0, 'cotizar' => true],
                    ['campo' => 'metodo', 'etiqueta' => 'Forma de pago', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'transferencia', 'opciones' => $metodos],
                    ['campo' => 'fecha', 'etiqueta' => 'Fecha del pago', 'tipo' => 'fecha', 'requerido' => true, 'valor' => $this->hoy->format('Y-m-d')],
                    ['campo' => 'inicio', 'etiqueta' => 'Desde', 'tipo' => 'fecha', 'requerido' => false,
                     'valor' => $this->planes->inicioSugerido((int) $n['id'], $this->hoy)->format('Y-m-d'), 'ayuda' => 'Se suma desde el vencimiento actual si aún no vence.'],
                    ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 200, 'ayuda' => 'N° de transferencia, factura…'],
                    ['campo' => 'dominio', 'etiqueta' => 'Dominio propio', 'tipo' => 'texto', 'requerido' => false, 'max' => 120, 'ayuda' => 'Solo con el pago anual (ej. barberiaelcorte.com).'],
                ]];
    }

    private function formExtender(array $c, array $datos): array
    {
        return ['accion' => 'extender_prueba', 'etiqueta' => 'Alargar demo', 'estilo' => 'normal',
                'confirmar' => '¿Alargar la demo de ' . $c['nombre'] . '?', 'datos' => $datos,
                'campos' => [['campo' => 'dias', 'etiqueta' => 'Días más', 'tipo' => 'dias', 'requerido' => true, 'valor' => 7, 'min' => 1, 'max' => 90]]];
    }

    private function formAtender(array $s, array $extra = []): array
    {
        return ['accion' => 'atender_solicitud', 'etiqueta' => 'Marcar atendida', 'estilo' => 'normal', 'confirmar' => null,
                'datos' => ['solicitud_id' => $s['id']],
                'campos' => array_merge([
                    ['campo' => 'resultado', 'etiqueta' => 'Resultado', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'atendida',
                     'opciones' => [['valor' => 'en_proceso', 'etiqueta' => 'En proceso'], ['valor' => 'atendida', 'etiqueta' => 'Atendida'],
                                    ['valor' => 'descartada', 'etiqueta' => 'Descartar']]],
                    ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 300],
                ], $extra)];
    }

    // ------------------------------------------------------------------
    // cotizar (§5.6) y planes (§5.7)
    // ------------------------------------------------------------------

    public function cotizar(array $d): array
    {
        $id = $this->idCliente($d['cliente_id'] ?? null, 'cliente_id');
        $n = $this->fila($id);
        [$plan, $periodo] = $this->planPeriodo($d, (string) $n['plan_codigo'], '');
        $c = $this->planes->cotizar($plan, $periodo, $id);   // plan + módulos del salón (Anexo B)
        $desde = $this->planes->inicioSugerido($id, $this->hoy);
        $hasta = $desde->modify('+' . $c['meses_recibidos'] . ' months')->modify('-1 day');
        return ['monto' => $c['monto'], 'meses' => $c['meses_recibidos'], 'desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d'),
                'detalle' => $this->detallePlan($plan, $periodo), 'incluye_dominio' => $c['incluye_dominio'], 'descuentos' => [],
                'modulos' => $this->modulosCliente($n, $plan)];
    }

    private function detallePlan(string $plan, string $periodo): string
    {
        $nombre = (string) ($this->db->query('SELECT nombre FROM planes WHERE codigo = ' . $this->db->quote($plan))->fetchColumn() ?: $plan);
        $x = $this->planes->periodos()[$periodo];
        return $periodo === 'mensual' ? "$nombre mensual"
            : "$nombre $periodo (paga {$x['paga']}, recibe {$x['recibe']}" . ($x['dominio'] ? ' + dominio propio' : '') . ')';
    }

    /** Plan y período de los datos (validados). $ruta: prefijo del campo para el error ("datos."). */
    private function planPeriodo(array $d, string $planDefecto, string $ruta): array
    {
        $plan = $d['plan'] ?? null;
        if ($plan === null || $plan === '') $plan = $planDefecto;
        if (!is_string($plan) || !$this->planExiste($plan)) throw TukanCentralError::campo($ruta . 'plan', 'Ese plan no existe en TuSalón.');
        $periodo = $d['periodo'] ?? null;
        if (($periodo === null || $periodo === '') && array_key_exists('meses', $d)) {
            throw TukanCentralError::campo($ruta . 'meses', 'TuSalón no cobra por meses sueltos: elige mensual, semestral o anual.');
        }
        if (!is_string($periodo) || !isset($this->planes->periodos()[$periodo])) {
            throw TukanCentralError::campo($ruta . 'periodo', 'Elige el tiempo: mensual, semestral o anual.');
        }
        return [$plan, $periodo];
    }

    private function planExiste(string $plan): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM planes WHERE codigo = ?');
        $st->execute([$plan]);
        return (bool) $st->fetchColumn();
    }

    public function planesV2(): array
    {
        $a = $this->planes->ajustes();
        $cuantos = $this->db->query('SELECT plan_codigo, count(*) FROM salones WHERE eliminado_en IS NULL GROUP BY plan_codigo')->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['editable' => true, 'planes' => array_map(fn($p) => $this->plan($p, (int) ($cuantos[$p['codigo']] ?? 0)), $this->planes->lista()),
                'ajustes' => ['dias_prueba' => (int) $a['dias_prueba'], 'dias_demo' => (int) $a['dias_prueba'], 'dias_gracia' => (int) $a['dias_gracia'],
                              'dias_gracia_demo' => 0, 'registro_abierto' => false]];   // no hay registro abierto: todo se pide demo
    }

    private function plan(array $p, int $clientes): array
    {
        $precios = [];
        foreach ($this->planes->periodos() as $per => $x) {
            $c = $this->planes->cotizar((string) $p['codigo'], $per);
            $precios[] = ['periodo' => $per, 'meses' => $x['recibe'], 'precio' => $c['monto'], 'meses_gratis' => $x['recibe'] - $x['paga'],
                          'incluye_dominio' => $x['dominio']];
        }
        return ['codigo' => (string) $p['codigo'], 'nombre' => (string) $p['nombre'], 'activo' => true, 'para' => null,
                'modulos' => $this->modulos->modulosDePlan((string) ($p['modulos'] ?? '')),
                'descripcion' => $p['descripcion'] ?? null, 'precios' => $precios,
                'limites' => ['Personas que atienden' => $p['max_profesionales'] === null ? 'Ilimitadas' : (string) $p['max_profesionales']],
                'clientes' => $clientes];
    }

    private function camposPlan(?array $p): array
    {
        return [
            ['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => $p['codigo'] ?? null, 'opciones' => $this->opcionesPlan()],
            ['campo' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'texto', 'requerido' => false, 'max' => 50],
            ['campo' => 'precio_mensual', 'etiqueta' => 'Precio al mes ($)', 'tipo' => 'dinero', 'requerido' => false, 'min' => 0],
            ['campo' => 'descripcion', 'etiqueta' => 'Descripción', 'tipo' => 'texto', 'requerido' => false, 'max' => 300],
            ['campo' => 'max_profesionales', 'etiqueta' => 'Máximo de personas que atienden (vacío = ilimitadas)', 'tipo' => 'numero', 'requerido' => false, 'min' => 1],
        ];
    }

    // ------------------------------------------------------------------
    // Solicitudes (§5.8, §5.9): registros nuevos y recordatorios que se arman al momento
    // ------------------------------------------------------------------

    /** Todas las solicitudes que corresponden hoy, con su estado según tukan_central_atenciones. */
    private function todasLasSolicitudes(): array
    {
        if ($this->solicitudesCache !== null) return $this->solicitudesCache;
        $hoy = $this->hoy->format('Y-m-d');
        $hace = fn(int $d) => $this->hoy->modify("-$d days")->format('Y-m-d');
        $pagos = $this->pagosResumen();
        $items = [];
        foreach ($this->filas() as $id => $n) {
            $c = $this->clasificar($n);
            $nat = $c['nativo'];
            if (in_array($nat, ['suspendido', 'cancelado'], true)) continue;
            $plan = ['codigo' => (string) $n['plan_codigo'], 'nombre' => (string) $n['plan_nombre']];
            $alta = substr((string) $n['creado_en'], 0, 10);
            if ($nat === 'prueba' && $n['prueba_hasta']) {
                $dias = $this->diasHasta($n['prueba_hasta']);
                $etapa = $dias === 0 ? 'vence_hoy' : ($dias <= 2 ? 'faltan_2' : null);
                if ($etapa) {
                    $desde = (new DateTimeImmutable($n['prueba_hasta']))->modify($etapa === 'vence_hoy' ? '+0 days' : '-2 days')->format('Y-m-d');
                    $items[] = $this->sol($n, $c, 'seguimiento_prueba', "seguimiento_prueba:$id:$etapa:{$n['prueba_hasta']}", 'recordatorio',
                        self::momento(max($desde, $alta)), $etapa === 'vence_hoy' ? 'alta' : 'normal',
                        $etapa === 'vence_hoy' ? 'La demo termina hoy' : 'Demo por terminar (faltan ' . self::dias($dias) . ')',
                        'Plan ' . $plan['nombre'] . ' · demo hasta el ' . self::fechaTexto($n['prueba_hasta']),
                        ['etapa' => $etapa, 'plan' => $plan, 'prueba_hasta' => $n['prueba_hasta'], 'dias' => $dias]);
                }
            }
            if ($nat === 'prueba_vencida' && $n['prueba_hasta'] >= $hace(self::DIAS_PRUEBA_TERMINADA)) {
                $items[] = $this->sol($n, $c, 'seguimiento_prueba', "seguimiento_prueba:$id:terminada:{$n['prueba_hasta']}", 'recordatorio',
                    self::momento((new DateTimeImmutable($n['prueba_hasta']))->modify('+1 day')->format('Y-m-d')), 'normal',
                    'La demo terminó sin pagar', 'Plan ' . $plan['nombre'] . ' · terminó el ' . self::fechaTexto($n['prueba_hasta']),
                    ['etapa' => 'terminada', 'plan' => $plan, 'prueba_hasta' => $n['prueba_hasta'], 'dias' => $this->diasHasta($n['prueba_hasta'])]);
            }
            $pago = (int) ($pagos[$id]['cantidad'] ?? 0) > 0;
            if ($nat === 'activo' && $n['activo_hasta'] && $pago && $this->diasHasta($n['activo_hasta']) <= self::DIAS_RENOVACION) {
                $dias = $this->diasHasta($n['activo_hasta']);
                $items[] = $this->sol($n, $c, 'renovacion', "renovacion:$id:vence_pronto:{$n['activo_hasta']}", 'recordatorio',
                    self::momento((new DateTimeImmutable($n['activo_hasta']))->modify('-' . self::DIAS_RENOVACION . ' days')->format('Y-m-d')), 'normal',
                    $dias === 0 ? 'El plan vence hoy' : 'Plan por vencer (faltan ' . self::dias($dias) . ')',
                    'Plan ' . $plan['nombre'] . ' · vence el ' . self::fechaTexto($n['activo_hasta']),
                    ['etapa' => 'vence_pronto', 'plan' => $plan, 'activo_hasta' => $n['activo_hasta'], 'dias' => $dias]);
            }
            if (in_array($nat, ['por_vencer', 'vencido'], true) && $n['activo_hasta'] && $n['activo_hasta'] >= $hace(self::DIAS_VENCIDA)) {
                $items[] = $this->sol($n, $c, 'renovacion', "renovacion:$id:vencida:{$n['activo_hasta']}", 'recordatorio',
                    self::momento((new DateTimeImmutable($n['activo_hasta']))->modify('+1 day')->format('Y-m-d')), 'alta',
                    'Plan vencido sin renovar', 'Plan ' . $plan['nombre'] . ' · venció el ' . self::fechaTexto($n['activo_hasta'])
                        . ($nat === 'por_vencer' ? ' (en días de gracia)' : ''),
                    ['etapa' => 'vencida', 'plan' => $plan, 'activo_hasta' => $n['activo_hasta'], 'dias' => $this->diasHasta($n['activo_hasta']),
                     'en_gracia' => $nat === 'por_vencer']);
            }
        }
        // Pagos automáticos de Google Play de los últimos 30 días (aviso: solo hay que marcarlos "visto")
        $filas = null;
        $st = $this->db->prepare("SELECT su.id, su.salon_id, su.pagado_en, su.monto, su.periodo, su.inicio, su.fin, pl.nombre AS plan_nombre, su.plan_codigo
                                    FROM suscripciones su JOIN planes pl ON pl.codigo = su.plan_codigo
                                   WHERE su.metodo_pago = 'google_play' AND NOT su.anulada AND su.pagado_en >= ? ORDER BY su.id");
        $st->execute([$hace(self::DIAS_PAGO_AUTOMATICO)]);
        foreach ($st->fetchAll() as $s) {
            $filas ??= $this->filas();
            $n = $filas[(int) $s['salon_id']] ?? null;
            if (!$n) continue;
            $items[] = $this->sol($n, $this->clasificar($n), 'pago_automatico', 'pago_automatico:' . $s['id'], 'aviso', self::momento($s['pagado_en']), 'baja',
                'Pago automático por Google Play', 'Plan ' . $s['plan_nombre'] . ' ' . $s['periodo'] . ' · ' . self::dinero((float) $s['monto'])
                    . ' · hasta el ' . self::fechaTexto($s['fin']),
                ['pago_id' => (string) $s['id'], 'plan' => ['codigo' => $s['plan_codigo'], 'nombre' => $s['plan_nombre']], 'periodo' => $s['periodo'],
                 'desde' => $s['inicio'], 'hasta' => $s['fin'], 'forma_pago' => 'google_play'],
                round((float) $s['monto'], 2));
        }
        // Dominio incluido en el pago anual, por registrar
        foreach ($this->db->query("SELECT su.id, su.salon_id, su.pagado_en, su.monto, su.inicio, su.fin, pl.nombre AS plan_nombre, su.plan_codigo
                                     FROM suscripciones su JOIN planes pl ON pl.codigo = su.plan_codigo
                                    WHERE su.incluye_dominio AND su.dominio IS NULL AND NOT su.anulada ORDER BY su.id")->fetchAll() as $s) {
            $filas ??= $this->filas();
            $n = $filas[(int) $s['salon_id']] ?? null;
            if (!$n) continue;
            $items[] = $this->sol($n, $this->clasificar($n), 'dominio_gratis', 'dominio_gratis:' . $s['id'], 'recordatorio', self::momento($s['pagado_en']), 'normal',
                'Dominio propio por registrar (pago anual)', 'Pago anual del ' . self::fechaTexto($s['pagado_en']) . ' · ' . self::dinero((float) $s['monto']),
                ['pago_id' => (string) $s['id'], 'plan' => ['codigo' => $s['plan_codigo'], 'nombre' => $s['plan_nombre']], 'desde' => $s['inicio'], 'hasta' => $s['fin']],
                round((float) $s['monto'], 2));
        }
        // Anexo B y "todo se pide demo": pedidos con tabla propia (no van a tukan_central_atenciones)
        $items = array_merge($items, $this->solicitudesDemo(), $this->solicitudesModulos($filas ?? $this->filas()));
        // Estado según lo que el Central ya atendió
        $calculadas = array_values(array_filter($items, fn($s) => !in_array($s['tipo'], ['demo_pedida', 'cambio_modulos'], true)));
        if ($calculadas) {
            $ids = array_column($calculadas, 'id');
            $st = $this->db->prepare('SELECT * FROM tukan_central_atenciones WHERE solicitud_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            $at = [];
            foreach ($st->fetchAll() as $f) $at[$f['solicitud_id']] = $f;
            foreach ($items as &$s) {
                if (in_array($s['tipo'], ['demo_pedida', 'cambio_modulos'], true) || !isset($at[$s['id']])) continue;
                $f = $at[$s['id']];
                $s['estado'] = $s['estado_producto'] = (string) $f['estado'];
                $s['actualizado'] = self::momento($f['momento']);
                $s['detalle']['atencion'] = ['estado' => $f['estado'], 'nota' => $f['nota'], 'por' => trim(($f['nombre'] ?? '') . ' <' . $f['email'] . '>'),
                                             'momento' => self::momento($f['momento'])];
            }
            unset($s);
        }
        return $this->solicitudesCache = $items;
    }

    private function sol(array $n, array $c, string $tipo, string $id, string $categoria, ?string $fecha, string $prioridad,
                         string $titulo, string $resumen, array $detalle, ?float $monto = null): array
    {
        $cl = ['id' => (string) $n['id'], 'nombre' => (string) $n['nombre'], 'plan' => ['codigo' => (string) $n['plan_codigo']],
               'pagos' => ['cantidad' => (int) ($this->pagosResumen()[(int) $n['id']]['cantidad'] ?? 0)]] + $c;
        $tel = $n['telefono'] ?: null;
        $wa = WhatsApp::normalizarTelefono($tel);
        $m = $this->mensajes($n);
        $s = [
            'id' => $id, 'tipo' => $tipo, 'categoria' => $categoria, 'titulo' => $titulo, 'fecha' => $fecha, 'actualizado' => $fecha,
            'cliente_id' => (string) $n['id'],
            'quien' => ['nombre' => $n['dueno'] ?: null, 'organizacion' => (string) $n['nombre'], 'telefono' => $tel ?: null, 'whatsapp' => $wa,
                        'email' => $n['dueno_email'] ?: null],
            'resumen' => $resumen, 'monto' => $monto, 'estado' => 'pendiente', 'estado_producto' => 'pendiente', 'prioridad' => $prioridad,
            'vence_el' => null, 'detalle' => $detalle, 'adjuntos' => [], 'acciones_posibles' => [], 'ir' => '/?r=admin_salon&id=' . (int) $n['id'],
        ];
        $datos = ['cliente_id' => (string) $n['id']];
        $a = [];
        $texto = match ($tipo) {
            'seguimiento_prueba' => $m['recordar_prueba'] ?? $m['vencido'],
            'renovacion' => $m['recordar_renovacion'] ?? $m['vencido'],
            default => null,
        };
        if ($wa && $texto) $a[] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $wa, 'texto' => $texto];
        if ($tipo === 'seguimiento_prueba' && $this->puedeExtender($cl)) $a[] = $this->formExtender($cl, $datos);
        if (in_array($tipo, ['seguimiento_prueba', 'renovacion'], true)) $a[] = $this->formPago($n, $datos);
        if ($tipo === 'renovacion' && ($detalle['etapa'] ?? '') === 'vencida') {
            $a[] = ['accion' => 'suspender', 'etiqueta' => 'Suspender', 'estilo' => 'peligro', 'confirmar' => '¿Suspender ' . $n['nombre'] . '?', 'datos' => $datos,
                    'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => true, 'valor' => 'No renovó el plan', 'min' => 5, 'max' => 200]]];
        }
        if ($tipo === 'pago_automatico') {
            $a[] = ['accion' => 'atender_solicitud', 'etiqueta' => 'Visto', 'estilo' => 'principal', 'confirmar' => null,
                    'datos' => ['solicitud_id' => $s['id'], 'resultado' => 'atendida'],
                    'campos' => [['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 300]]];
        } else $a[] = $this->formAtender($s, $tipo === 'dominio_gratis'
            ? [['campo' => 'dominio', 'etiqueta' => 'Dominio registrado', 'tipo' => 'texto', 'requerido' => false, 'max' => 120, 'ayuda' => 'Se guarda en el pago anual (ej. barberiaelcorte.com).']]
            : []);
        $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuSalón', 'ir' => $s['ir']];
        $s['acciones_posibles'] = $a;
        return $s;
    }

    /** Solicitudes pendientes o en proceso (para el estado v1: cuántas y su huella). */
    public function solicitudesAbiertas(): array
    {
        return array_values(array_filter($this->todasLasSolicitudes(), fn($s) => in_array($s['estado'], ['pendiente', 'en_proceso'], true)));
    }

    /** Primeros 16 hex del sha256 de "id|estado" de las solicitudes abiertas, ordenados (§2.4). */
    public static function huella(array $solicitudes): string
    {
        $l = array_map(fn($s) => $s['id'] . '|' . $s['estado'], $solicitudes);
        sort($l, SORT_STRING);
        return substr(hash('sha256', implode("\n", $l)), 0, 16);
    }

    public function solicitudes(array $d): array
    {
        $estado = $this->opcion($d, 'estado', ['abiertas', 'cerradas', 'todas'], 'abiertas');
        $tipos = $d['tipos'] ?? [];
        if (!is_array($tipos) || !array_is_list($tipos) || array_filter($tipos, fn($t) => !is_string($t) || strlen($t) > 40)) {
            throw TukanCentralError::campo('tipos', 'La lista de tipos no es válida.');
        }
        $cliente = $d['cliente_id'] ?? null;
        if ($cliente !== null && !(is_int($cliente) || (is_string($cliente) && preg_match(self::ID, $cliente)))) {
            throw TukanCentralError::campo('cliente_id', 'El cliente no es válido.');
        }
        $desde = $this->fecha($d, 'desde') ?? $this->hoy->modify('-60 days')->format('Y-m-d');
        $pagina = $this->entero($d, 'pagina', 1, 1, 1000000);
        $porPagina = $this->entero($d, 'por_pagina', 100, 1, 200);
        $lista = array_values(array_filter($this->todasLasSolicitudes(), function ($s) use ($estado, $tipos, $cliente, $desde) {
            $abierta = in_array($s['estado'], ['pendiente', 'en_proceso'], true);
            if ($estado === 'abiertas' && !$abierta) return false;
            if ($estado === 'cerradas' && $abierta) return false;
            if ($estado !== 'abiertas' && substr((string) $s['actualizado'], 0, 10) < $desde) return false;
            if ($tipos && !in_array($s['tipo'], $tipos, true)) return false;
            return $cliente === null || $s['cliente_id'] === (string) $cliente;
        }));
        $orden = ['alta' => 0, 'normal' => 1, 'baja' => 2];
        usort($lista, fn($a, $b) => [$orden[$a['prioridad']], $b['fecha'], $a['id']] <=> [$orden[$b['prioridad']], $a['fecha'], $b['id']]);
        $contadores = [];
        foreach ($lista as $s) $contadores[$s['tipo']] = ($contadores[$s['tipo']] ?? 0) + 1;
        $total = count($lista);
        return ['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => max(1, (int) ceil($total / $porPagina)),
                'contadores' => (object) $contadores, 'solicitudes' => array_slice($lista, ($pagina - 1) * $porPagina, $porPagina)];
    }

    public function solicitud(array $d): array
    {
        $id = $d['id'] ?? null;
        if (!is_string($id) || !preg_match(self::ID, $id)) throw TukanCentralError::campo('id', 'Falta el id de la solicitud.');
        foreach ($this->todasLasSolicitudes() as $s) if ($s['id'] === $id) return $s;
        throw new TukanCentralError('no_encontrado', 'Esa solicitud ya no existe en TuSalón (quizás ya se resolvió).', 404);
    }

    // ------------------------------------------------------------------
    // finanzas (§5.11)
    // ------------------------------------------------------------------

    public function finanzas(array $d): array
    {
        $hasta = $this->mes($d, 'hasta') ?? $this->hoy->format('Y-m');
        $desde = $this->mes($d, 'desde') ?? (new DateTimeImmutable("$hasta-01"))->modify('-11 months')->format('Y-m');
        if ($desde > $hasta) throw TukanCentralError::campo('desde', 'El mes "desde" va antes que "hasta".');
        $ini = new DateTimeImmutable("$desde-01");
        $finRango = (new DateTimeImmutable("$hasta-01"))->modify('first day of next month');
        $meses = [];
        for ($m = $ini; $m < $finRango; $m = $m->modify('first day of next month')) $meses[] = $m->format('Y-m');
        if (count($meses) > 24) throw TukanCentralError::campo('desde', 'Pide como máximo 24 meses.');

        $vacio = fn(string $mes) => ['mes' => $mes, 'ingresos' => 0.0, 'pagos' => 0, 'clientes_que_pagaron' => 0, 'nuevos_pagadores' => 0, 'devengado' => 0.0,
                                     'por_plan' => [], 'por_periodo' => [], 'por_metodo' => [], 'otros_ingresos' => [], 'costos' => []];
        $r = [];
        foreach ($meses as $mes) $r[$mes] = $vacio($mes);
        $clientes = [];
        $gp = [];
        $st = $this->db->prepare("SELECT su.salon_id, su.plan_codigo, pl.nombre AS plan_nombre, su.periodo, su.meses_recibidos, su.monto, su.metodo_pago,
                                         to_char(su.pagado_en, 'YYYY-MM') AS mes
                                    FROM suscripciones su JOIN planes pl ON pl.codigo = su.plan_codigo
                                   WHERE NOT su.anulada AND " . str_replace('suscripciones.', 'su.', PanelTukan::SQL_PAGO_CUENTA) . "
                                     AND su.pagado_en >= ? AND su.pagado_en < ? ORDER BY su.pagado_en, su.id");
        $st->execute([$ini->format('Y-m-d'), $finRango->format('Y-m-d')]);
        $sumar = function (array &$grupo, string $clave, array $base, float $monto): void {
            $grupo[$clave] ??= $base + ['monto' => 0.0, 'pagos' => 0];
            $grupo[$clave]['monto'] += $monto;
            $grupo[$clave]['pagos']++;
        };
        foreach ($st->fetchAll() as $p) {
            $mes = $p['mes'];
            $monto = (float) $p['monto'];
            $r[$mes]['ingresos'] += $monto;
            $r[$mes]['pagos']++;
            $clientes[$mes][(int) $p['salon_id']] = true;
            if ($p['metodo_pago'] === 'google_play') $gp[$mes] = ($gp[$mes] ?? 0) + $monto;
            $sumar($r[$mes]['por_plan'], (string) $p['plan_codigo'], ['plan' => (string) $p['plan_codigo'], 'nombre' => (string) $p['plan_nombre']], $monto);
            $sumar($r[$mes]['por_periodo'], $p['periodo'] . '|' . $p['meses_recibidos'], ['periodo' => (string) $p['periodo'], 'meses' => (int) $p['meses_recibidos']], $monto);
            $met = (string) ($p['metodo_pago'] ?? 'otro');
            $sumar($r[$mes]['por_metodo'], $met, ['metodo' => $met, 'nombre' => self::nombreMetodo($met)], $monto);
        }
        foreach ($this->db->query("SELECT salon_id, to_char(min(pagado_en), 'YYYY-MM') AS mes FROM suscripciones WHERE NOT anulada AND " . PanelTukan::SQL_PAGO_CUENTA
                                  . ' GROUP BY salon_id')->fetchAll() as $f) {
            if (isset($r[$f['mes']])) $r[$f['mes']]['nuevos_pagadores']++;
        }
        // Devengado: cada pago repartido por días entre su inicio y su fin (de un salón en la papelera, solo hasta el mes en que se borró)
        $st = $this->db->prepare("SELECT su.monto, su.inicio, su.fin, to_char(n.eliminado_en, 'YYYY-MM') AS borrado
                                    FROM suscripciones su JOIN salones n ON n.id = su.salon_id
                                   WHERE NOT su.anulada AND " . str_replace('suscripciones.', 'su.', PanelTukan::SQL_PAGO_CUENTA) . ' AND su.inicio < ? AND su.fin >= ?');
        $st->execute([$finRango->format('Y-m-d'), $ini->format('Y-m-d')]);
        foreach ($st->fetchAll() as $s) {
            $i = new DateTimeImmutable($s['inicio']);
            $f = new DateTimeImmutable($s['fin']);
            $total = (int) $i->diff($f)->days + 1;
            if ($total <= 0) continue;
            foreach ($meses as $mes) {
                if ($s['borrado'] !== null && $mes > $s['borrado']) continue;
                $mi = new DateTimeImmutable("$mes-01");
                $mf = $mi->modify('last day of this month');
                $a = max($i, $mi);
                $b = min($f, $mf);
                if ($b < $a) continue;
                $r[$mes]['devengado'] += (float) $s['monto'] * ((int) $a->diff($b)->days + 1) / $total;
            }
        }
        $tot = ['ingresos' => 0.0, 'otros_ingresos' => 0.0, 'costos' => 0.0, 'pagos' => 0];
        $ordenar = function (array $g): array {
            $g = array_map(function ($x) { $x['monto'] = round($x['monto'], 2); return $x; }, array_values($g));
            usort($g, fn($a, $b) => $b['monto'] <=> $a['monto']);
            return $g;
        };
        foreach ($r as $mes => &$x) {
            $x['ingresos'] = round($x['ingresos'], 2);
            $x['devengado'] = round($x['devengado'], 2);
            $x['clientes_que_pagaron'] = count($clientes[$mes] ?? []);
            $x['por_plan'] = $ordenar($x['por_plan']);
            $x['por_periodo'] = $ordenar($x['por_periodo']);
            $x['por_metodo'] = $ordenar($x['por_metodo']);
            if (!empty($gp[$mes])) {   // Google Play se queda con una parte (estimada) de lo que se cobró por la app
                $x['costos'][] = ['concepto' => 'comision_google_play', 'categoria' => 'tiendas_apps', 'nombre' => 'Comisión de Google Play (15 %, estimada)',
                                  'monto' => round($gp[$mes] * self::COMISION_GOOGLE_PLAY, 2), 'estimado' => true];
                $tot['costos'] += round($gp[$mes] * self::COMISION_GOOGLE_PLAY, 2);
            }
            $tot['ingresos'] += $x['ingresos'];
            $tot['pagos'] += $x['pagos'];
        }
        unset($x);
        $tot['ingresos'] = round($tot['ingresos'], 2);
        $tot['costos'] = round($tot['costos'], 2);
        // Anexo B: ingreso mensual recurrente = lo que vale al mes cada salón que paga (plan + módulos), y por módulo extra
        $mrr = 0.0;
        $porModulo = [];
        $cat = $this->modulos->catalogo();
        foreach ($this->filas() as $n) {
            $c = $this->cliente($n);
            if ($c['mensual_estimado'] <= 0) continue;
            $mrr += $c['mensual_estimado'];
            foreach ($c['modulos']['extra'] as $mid) {
                $porModulo[$mid] ??= ['modulo' => $mid, 'nombre' => $cat[$mid]['nombre'], 'clientes' => 0, 'monto' => 0.0];
                $porModulo[$mid]['clientes']++;
                $porModulo[$mid]['monto'] += $cat[$mid]['precio_mensual'];
            }
        }
        $porModulo = array_map(function ($x) { $x['monto'] = round($x['monto'], 2); return $x; }, array_values($porModulo));
        usort($porModulo, fn($a, $b) => [$b['monto'], $a['modulo']] <=> [$a['monto'], $b['modulo']]);
        return ['moneda' => 'USD',
                'criterio' => 'Pagos de suscripción no anulados (también los de Google Play), por fecha de pago. Devengado: cada pago repartido por días entre su inicio y su fin. La comisión de Google Play es un cálculo (15 %). '
                              . 'Un salón en la papelera no cuenta desde el día en que se borró. MRR: lo que vale al mes cada salón que paga (plan + módulos extra − quitados, o su precio acordado). '
                              . 'por_modulo: ingreso estimado al mes de cada módulo extra (fuera del plan) de los salones que pagan.',
                'mrr' => round($mrr, 2), 'por_modulo' => $porModulo,
                'meses' => array_values($r), 'totales' => $tot];
    }

    // ------------------------------------------------------------------
    // ejecutar (§6) con idempotencia (§7.1) y auditoría (§7.3)
    // ------------------------------------------------------------------

    private function ejecutar(array $p, array $d, string $bh): array
    {
        $op = $d['operacion_id'] ?? null;
        if (!is_string($op) || !preg_match('/^[0-9a-f]{32}$/', $op)) throw TukanCentralError::campo('operacion_id', 'Falta la clave de la operación (32 letras hex).');
        $accion = $d['accion'] ?? null;
        if (!is_string($accion) || $accion === '') throw TukanCentralError::campo('accion', 'Falta la operación.');
        if (!in_array($accion, self::OPERACIONES, true)) {
            throw new TukanCentralError('no_permitido', 'TuSalón no tiene la operación "' . mb_substr($accion, 0, 40) . '".', 403, null, ['motivo' => 'no_disponible']);
        }
        $datos = $d['datos'] ?? [];
        if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) throw TukanCentralError::campo('datos', 'Los datos de la operación no son válidos.');
        $this->quien = trim(($p['nombre'] ?: 'Panel Tukán Central') . ' <' . $p['email'] . '>');
        $clienteId = $d['cliente_id'] ?? ($datos['cliente_id'] ?? null);
        if ($clienteId !== null && !(is_int($clienteId) || (is_string($clienteId) && preg_match(self::ID, $clienteId)))) {
            throw TukanCentralError::campo('cliente_id', 'El cliente no es válido.');
        }

        // 1. ¿Ya se hizo (o se está haciendo) esta operación?
        $this->db->exec("DELETE FROM tukan_central_operaciones WHERE creado < now() - interval '90 days'");
        $st = $this->db->prepare("INSERT INTO tukan_central_operaciones (operacion_id, accion, cliente_id, cuerpo_hash, estado, email)
                                  VALUES (?, ?, ?, ?, 'en_curso', ?) ON CONFLICT (operacion_id) DO NOTHING");
        $st->execute([$op, $accion, $clienteId === null ? null : (string) $clienteId, $bh, $p['email']]);
        if ($st->rowCount() !== 1) {
            $st = $this->db->prepare('SELECT * FROM tukan_central_operaciones WHERE operacion_id = ?');
            $st->execute([$op]);
            $g = $st->fetch();
            if (!$g) throw new TukanCentralError('conflicto', 'La operación está en curso. Intenta en unos segundos.', 409, null, ['motivo' => 'en_curso']);
            if (!hash_equals((string) $g['cuerpo_hash'], $bh)) {
                throw new TukanCentralError('conflicto', 'Esa operación ya se usó con otros datos.', 409, null, ['motivo' => 'otros_datos']);
            }
            if ($g['estado'] === 'en_curso') throw new TukanCentralError('conflicto', 'La operación está en curso. Intenta en unos segundos.', 409, null, ['motivo' => 'en_curso']);
            $json = json_decode((string) $g['respuesta'], true) ?: [];
            $json['repetida'] = true;
            return [(int) $g['http'], $json];
        }

        // 2. Hacerla en una transacción (con la fila del cliente bloqueada) y guardar la respuesta sin claves
        try {
            $this->db->beginTransaction();
            try {
                [$adminId] = $this->v1->buscarSuper($p);
            } catch (TukanCentralRechazo $e) {
                throw new TukanCentralError('no_permitido', $e->getMessage(), 403);
            }
            $id = null;
            if (!in_array($accion, ['crear_demo', 'atender_solicitud', 'guardar_plan', 'guardar_modulos'], true)) {
                if ($clienteId === null) throw TukanCentralError::campo('cliente_id', 'Falta el cliente.');
                $id = $this->bloquear($clienteId);
                if (!in_array($accion, self::OPERACIONES_PAPELERA, true) && $this->enPapelera($id)) {
                    throw new TukanCentralError('conflicto', 'Ese salón está en la papelera. Primero restáuralo.', 409, null, ['motivo' => 'en_papelera']);
                }
            }
            if ($accion === 'borrar_cliente' && ($p['rol'] ?? null) !== 'dueno') {   // Anexo A: borrar es solo del dueño de Tukán
                throw new TukanCentralError('no_permitido', 'Solo el dueño del Panel Tukán Central puede borrar clientes.', 403, null, ['motivo' => 'rol']);
            }
            $r = $this->operar($accion, $id, $datos, $p);
            $id = $r['cliente_id'] ?? $id;
            $this->db->prepare('INSERT INTO registro_admin (admin_id, salon_id, accion) VALUES (?, ?, ?)')
                     ->execute([$adminId, $id, mb_substr('Panel Tukán Central · ' . $r['auditoria'] . (!empty($r['sin_cambios']) ? ' (sin cambios)' : '')
                                                         . ' (op ' . substr($op, 0, 8) . ')', 0, 300)]);
            $this->olvidar();
            $fila = $id ? $this->fila($id) : null;
            $json = $this->sobre('ejecutar') + [
                'operacion' => $accion, 'operacion_id' => $op, 'repetida' => false, 'sin_cambios' => (bool) ($r['sin_cambios'] ?? false),
                'mensaje' => $r['mensaje'], 'resultado' => $r['resultado'], 'cliente' => $fila ? $this->cliente($fila) : null,
                'solicitud' => $r['solicitud'] ?? null,
            ] + ($r['arriba'] ?? []);   // borrar_cliente: "copia" y "borrar_definitivo_el" también arriba (Anexo A)
            $this->db->prepare("UPDATE tukan_central_operaciones SET estado = 'ok', http = 200, respuesta = ?, cliente_id = ?, terminado = now() WHERE operacion_id = ?")
                     ->execute([json_encode(self::sinClaves($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                                $id === null ? ($clienteId === null ? null : (string) $clienteId) : (string) $id, $op]);
            $this->db->commit();
            return [200, $json];
        } catch (TukanCentralError $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->olvidar();
            if ($e->codigo === 'interno') {
                $this->db->prepare('DELETE FROM tukan_central_operaciones WHERE operacion_id = ?')->execute([$op]);
                throw $e;
            }
            // 3. Error de validación: queda guardado (el reintento recibe lo mismo)
            $this->db->prepare("UPDATE tukan_central_operaciones SET estado = 'error', http = ?, respuesta = ?, terminado = now() WHERE operacion_id = ?")
                     ->execute([$e->http, json_encode($e->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $op]);
            throw $e;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->olvidar();
            $this->db->prepare('DELETE FROM tukan_central_operaciones WHERE operacion_id = ?')->execute([$op]);   // se puede reintentar
            throw $e;
        }
    }

    /** La respuesta que se guarda: sin contraseñas ni mensajes que las lleven (§6.2 "Claves de un solo uso"). */
    public static function sinClaves(array $json): array
    {
        $r = $json['resultado'] ?? null;
        if (is_array($r)) {
            if (isset($r['acceso']) && is_array($r['acceso']) && array_key_exists('clave', $r['acceso'])) {
                $r['acceso']['clave'] = null;
                $r['acceso']['clave_oculta'] = true;
            }
            if (array_key_exists('clave', $r)) {
                $r['clave'] = null;
                $r['clave_oculta'] = true;
            }
            if (isset($r['acceso']) || array_key_exists('clave', $r)) {
                if (array_key_exists('mensaje_whatsapp', $r)) $r['mensaje_whatsapp'] = null;
                if (array_key_exists('whatsapp_url', $r)) $r['whatsapp_url'] = null;
            }
            $json['resultado'] = $r;
        }
        return $json;
    }

    private function enPapelera(int $id): bool
    {
        return (bool) $this->db->query("SELECT eliminado_en IS NOT NULL FROM salones WHERE id = $id")->fetchColumn();
    }

    private function bloquear(int|string $clienteId): int
    {
        if (!preg_match('/^\d{1,10}$/', (string) $clienteId)) throw new TukanCentralError('no_encontrado', 'Ese salón no existe en TuSalón.', 404);
        $st = $this->db->prepare('SELECT id FROM salones WHERE id = ? FOR UPDATE');
        $st->execute([(int) $clienteId]);
        $id = $st->fetchColumn();
        if ($id === false) throw new TukanCentralError('no_encontrado', 'Ese salón ya no existe en TuSalón.', 404);
        return (int) $id;
    }

    private function operar(string $accion, ?int $id, array $d, array $p): array
    {
        try {
            return match ($accion) {
                'crear_demo' => $this->opCrearDemo($d),
                'extender_prueba' => $this->opExtenderPrueba($id, $d),
                'registrar_pago' => $this->opRegistrarPago($id, $d),
                'anular_pago' => $this->opAnularPago($id, $d),
                'suspender' => $this->opEstado($id, $d, 'suspendido'),
                'cancelar' => $this->opEstado($id, $d, 'cancelado'),
                'reactivar' => $this->opReactivar($id),
                'cambiar_plan' => $this->opCambiarPlan($id, $d),
                'nueva_clave_dueno' => $this->opNuevaClave($id),
                'nota_interna' => $this->opNota($id, $d),
                'atender_solicitud' => $this->opAtender($d, $p),
                'guardar_plan' => $this->opGuardarPlan($d),
                'pausar' => $this->opPausar($id, $d),
                'borrar_cliente' => $this->opBorrar($id, $d, $p),
                'restaurar_cliente' => $this->opRestaurar($id),
                'cambiar_modulos' => $this->opCambiarModulos($id, $d, $p),
                'guardar_modulos' => $this->opGuardarModulos($d),
            };
        } catch (TukanCentralError | PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {   // reglas de TuSalón (Planes, PanelTukan, Altas): mensaje en español para mostrar
            throw new TukanCentralError('invalido', $e->getMessage(), 422);
        }
    }

    private function opCrearDemo(array $d): array
    {
        $nombre = $this->textoReq($d, 'nombre', 3, 120);
        $contacto = $this->textoReq($d, 'contacto_nombre', 2, 80);
        $email = $d['email'] ?? null;
        if (!is_string($email) || mb_strlen(trim($email)) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw TukanCentralError::campo('datos.email', 'El correo no es válido.');
        }
        $email = strtolower(trim($email));
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE lower(email) = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) throw TukanCentralError::campo('datos.email', 'Ya existe una cuenta de TuSalón con ese correo.');
        $telefono = $this->textoReq($d, 'telefono', 9, 30);
        if (strlen((string) preg_replace('/\D+/', '', $telefono)) < 9) throw TukanCentralError::campo('datos.telefono', 'El teléfono debe tener al menos 9 dígitos.');
        $plan = $d['plan'] ?? null;
        if ($plan === null || $plan === '') $plan = 'completa';
        if (!is_string($plan) || !$this->planExiste($plan)) throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en TuSalón.');
        $diasDefecto = max(1, (int) $this->planes->ajuste('dias_prueba'));
        $dias = $this->entero($d, 'dias', $diasDefecto, 1, 90, 'datos.');
        $extra = $d['extra'] ?? [];
        if (!is_array($extra) || ($extra !== [] && array_is_list($extra))) throw TukanCentralError::campo('datos.extra', 'Los datos propios no son válidos.');
        // Anexo B: la demo ve todos los módulos, salvo que se elijan (datos.modulos o extra.modulos: lista o "a,b")
        $mods = $this->listaModulos(array_key_exists('modulos', $d) ? $d['modulos'] : ($extra['modulos'] ?? null), 'datos.modulos');
        if ($mods !== null) $mods = Modulos::conDependencias($mods);
        if ($mods === Modulos::ids()) $mods = null;
        // Pedido de demo del formulario público
        $sol = $d['solicitud_id'] ?? null;
        $pedido = null;
        if ($sol !== null && $sol !== '') {
            if (!is_string($sol) || !preg_match('/^demo_pedida:(\d{1,10})$/', $sol, $mm)) throw TukanCentralError::campo('datos.solicitud_id', 'La solicitud debe ser un pedido de demo (demo_pedida:N).');
            $st = $this->db->prepare('SELECT * FROM pedidos_demo WHERE id = ? FOR UPDATE');
            $st->execute([(int) $mm[1]]);
            $pedido = $st->fetch();
            if (!$pedido) throw new TukanCentralError('no_encontrado', 'Ese pedido de demo ya no existe.', 404);
            if (!in_array($pedido['estado'], ['nueva', 'contactada'], true)) {
                throw new TukanCentralError('conflicto', 'Ese pedido de demo ya fue ' . (PedidosDemo::ESTADOS[$pedido['estado']] ?? $pedido['estado']) . '.', 409, null,
                                            ['motivo' => 'ya_atendida', 'estado' => $pedido['estado']]);
            }
        }

        $clave = self::claveNueva();   // igual que "Nuevo salón" del panel de Tukán
        $alta = (new Altas($this->db))->crearSalonConDueno($nombre, $contacto, $telefono, $email, $clave, $plan, $pedido ? 'demo_pedida' : 'central');
        $st = $this->db->prepare('UPDATE salones SET prueba_hasta = ?::date + ?::int, modulos_demo = ? WHERE id = ? RETURNING prueba_hasta');
        $st->execute([$this->hoy->format('Y-m-d'), $dias, $mods === null ? null : ($mods === [] ? Modulos::NINGUNO : implode(',', $mods)), $alta['salon_id']]);
        $hasta = (string) $st->fetchColumn();
        if ($pedido) {
            (new PedidosDemo($this->db))->marcar((int) $pedido['id'], 'demo_creada', trim(($this->quien ?? 'Panel Tukán Central')), 'Demo creada: ' . $nombre, $alta['salon_id']);
            if (trim((string) $pedido['necesita']) !== '' || trim((string) $pedido['modulos']) !== '') {
                $this->panel->guardarNotas($alta['salon_id'], trim('Pedido de demo: ' . trim((string) $pedido['necesita'])
                    . (trim((string) $pedido['modulos']) !== '' ? ' · Módulos de interés: ' . implode(', ', array_map([$this->modulos, 'nombre'], Modulos::limpiar($pedido['modulos']))) : '')
                    . ($pedido['ciudad'] ? ' · Ciudad: ' . $pedido['ciudad'] : '')));
            }
        }
        $primer = explode(' ', $contacto)[0];
        $enlace = self::urlSitio($alta['slug']);
        $msg = "¡Hola $primer! Tu salón *$nombre* ya está listo en TuSalón, con servicios de ejemplo y el horario para que pruebes todo.\n\n"
             . "🌐 Reservas en línea: $enlace\n🔐 Panel: " . self::urlLogin() . "\n👤 Usuario: $email\n🔑 Contraseña: $clave\n\n"
             . 'Te recomendamos cambiar la contraseña al entrar (Mi cuenta). La demo dura hasta el ' . self::fechaTexto($hasta) . '.';
        $wa = WhatsApp::normalizarTelefono($telefono);
        return [
            'cliente_id' => $alta['salon_id'],
            'mensaje' => "Demo creada: $nombre, hasta el $hasta" . ($mods === null ? ', con todos los módulos.' : ', con ' . count($mods) . ' módulos.'),
            'solicitud' => $pedido ? $this->solicitudDe('demo_pedida:' . $pedido['id']) : null,
            'resultado' => ['cliente_id' => (string) $alta['salon_id'], 'prueba_hasta' => $hasta,
                            'acceso' => ['url_panel' => self::urlLogin(), 'usuario' => $email, 'clave' => $clave],
                            'enlace_publico' => $enlace, 'mensaje_whatsapp' => $msg,
                            'whatsapp_url' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg) : null,
                            'correo_enviado' => false,   // TuSalón todavía no envía correo de bienvenida
                            'modulos' => $mods ?? Modulos::ids()],
            'auditoria' => "Demo creada: $nombre (plan $plan, demo hasta $hasta" . ($mods === null ? ', todos los módulos' : ', módulos: ' . implode(',', $mods)) . ')'
                           . ($pedido ? ' · pedido de demo N.º ' . $pedido['id'] : ''),
        ];
    }

    private function opExtenderPrueba(int $id, array $d): array
    {
        $dias = $this->entero($d, 'dias', null, 1, 90, 'datos.');
        $c = $this->cliente($this->fila($id));
        if (!$this->puedeExtender($c)) {
            throw new TukanCentralError('no_permitido', 'Solo se alarga la demo de quien está en demo o la terminó sin pagar nunca.', 403, null,
                                        ['motivo' => 'ya_paga', 'estado' => $c['estado']]);
        }
        $hasta = $this->panel->extenderPrueba($id, $dias, $this->hoy);
        // Reinicia los avisos de la prueba: los recordatorios viejos ya no aplican
        $this->db->prepare("DELETE FROM tukan_central_atenciones WHERE solicitud_id LIKE ?")->execute(["seguimiento_prueba:$id:%"]);
        return ['mensaje' => "Demo de {$c['nombre']} hasta el " . self::fechaTexto($hasta) . '.',
                'resultado' => ['prueba_hasta' => $hasta], 'auditoria' => "Demo extendida $dias días, hasta $hasta"];
    }

    private function opRegistrarPago(int $id, array $d): array
    {
        $n = $this->fila($id);
        [$plan, $periodo] = $this->planPeriodo($d, (string) $n['plan_codigo'], 'datos.');
        $metodo = $d['metodo'] ?? null;
        if (!is_string($metodo) || !isset(PanelTukan::METODOS[$metodo])) throw TukanCentralError::campo('datos.metodo', 'Elige la forma de pago.');
        $monto = $this->dineroOpc($d, 'monto', 'datos.');
        $fecha = $this->fecha($d, 'fecha', 'datos.') ?? $this->hoy->format('Y-m-d');
        if ($fecha > $this->hoy->format('Y-m-d') || $fecha < $this->hoy->modify('-366 days')->format('Y-m-d')) {
            throw TukanCentralError::campo('datos.fecha', 'La fecha del pago debe ser de hoy o del último año.');
        }
        $inicio = $this->fecha($d, 'inicio', 'datos.') ?? $this->planes->inicioSugerido($id, $this->hoy)->format('Y-m-d');
        $nota = $this->texto($d, 'nota', 200, 'datos.');
        $dominio = $this->texto($d, 'dominio', 120, 'datos.');
        if ($dominio !== '' && !$this->planes->periodos()[$periodo]['dominio']) {
            throw TukanCentralError::campo('datos.dominio', 'El dominio propio solo viene con el pago anual.');
        }
        try {
            $r = $this->panel->registrarPago($id, ['plan' => $plan, 'periodo' => $periodo, 'inicio' => $inicio, 'metodo' => $metodo,
                                                   'monto' => $monto === null ? '' : number_format($monto, 2, '.', ''), 'dominio' => $dominio, 'fecha' => $fecha]);
        } catch (PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $campo = str_contains($e->getMessage(), 'dominio') ? 'datos.dominio' : (str_contains($e->getMessage(), 'monto') ? 'datos.monto' : null);
            throw new TukanCentralError('invalido', $e->getMessage(), 422, $campo);
        }
        $hasta = (string) $this->db->query('SELECT activo_hasta FROM salones WHERE id = ' . $id)->fetchColumn();
        $montoFinal = round((float) $r['monto'], 2);
        return ['mensaje' => "Pago registrado. {$n['nombre']} queda activo hasta el " . self::fechaTexto($hasta) . '.',
                'resultado' => ['pago_id' => (string) $r['id'], 'activo_hasta' => $hasta, 'monto' => $montoFinal, 'comision' => null],
                'auditoria' => "Pago registrado: $plan $periodo " . self::dinero($montoFinal) . ' (' . PanelTukan::METODOS[$metodo] . ") · pagó el $fecha"
                               . ($nota !== '' ? " · $nota" : '')];
    }

    private function opAnularPago(int $id, array $d): array
    {
        $pagoId = $d['pago_id'] ?? null;
        if (!(is_int($pagoId) || (is_string($pagoId) && preg_match('/^\d{1,10}$/', $pagoId)))) throw TukanCentralError::campo('datos.pago_id', 'Falta el pago.');
        $motivo = $this->textoReq($d, 'motivo', 5, 200);
        $st = $this->db->prepare('SELECT * FROM suscripciones WHERE id = ? AND salon_id = ?');
        $st->execute([(int) $pagoId, $id]);
        $s = $st->fetch();
        if (!$s) throw new TukanCentralError('no_encontrado', 'Ese pago no es de este salón o ya no existe.', 404);
        if ($s['anulada']) throw new TukanCentralError('conflicto', 'Ese pago ya estaba anulado.', 409, null, ['motivo' => 'ya_anulado']);
        $this->planes->anularPago((int) $pagoId);
        $f = $this->db->query("SELECT estado, activo_hasta FROM salones WHERE id = $id")->fetch();
        $aviso = '';
        if ($f['activo_hasta'] === null && $f['estado'] === 'activo') {   // no quedan pagos: vuelve a su prueba (ya terminada o no)
            $this->db->exec("UPDATE salones SET estado = 'prueba' WHERE id = $id");
            $aviso = ' No le quedan pagos: vuelve al estado de prueba.';
        }
        return ['mensaje' => 'Pago anulado.' . ($f['activo_hasta'] ? ' Queda pagado hasta el ' . self::fechaTexto($f['activo_hasta']) . '.' : '') . $aviso,
                'resultado' => ['activo_hasta' => $f['activo_hasta']],
                'auditoria' => "Pago anulado N.º {$s['id']} (" . self::dinero((float) $s['monto']) . "): $motivo"];
    }

    private function opEstado(int $id, array $d, string $estado): array
    {
        $motivo = $this->textoReq($d, 'motivo', 5, 200);
        $n = $this->fila($id);
        $ya = $estado === 'suspendido' ? in_array($n['estado'], ['suspendido', 'cancelado'], true) : $n['estado'] === 'cancelado';
        $palabra = $estado === 'suspendido' ? 'suspendido' : 'cancelado';
        if ($ya) {
            return ['mensaje' => "{$n['nombre']} ya estaba $palabra.", 'sin_cambios' => true,
                    'resultado' => ['estado' => $this->clasificar($n)['estado']], 'auditoria' => ucfirst($palabra) . ": $motivo"];
        }
        $this->panel->cambiarEstado($id, $estado);
        if ($estado === 'suspendido') {   // sin tipo: el salón ve el texto genérico de "cuenta pausada"
            $this->db->prepare('UPDATE salones SET pausa_tipo = NULL, pausa_motivo = ?, pausa_en = now() WHERE id = ?')->execute([$motivo, $id]);
        }
        return ['mensaje' => "{$n['nombre']} quedó $palabra. Sus datos se guardan.", 'resultado' => ['estado' => 'suspendido'],
                'auditoria' => ($estado === 'suspendido' ? 'Suspendido' : 'Cancelado') . ": $motivo"];
    }

    private function opReactivar(int $id): array
    {
        $n = $this->fila($id);
        if (!in_array($n['estado'], ['suspendido', 'cancelado'], true)) {
            return ['mensaje' => "{$n['nombre']} no estaba suspendido.", 'sin_cambios' => true,
                    'resultado' => ['estado' => $this->clasificar($n)['estado']], 'auditoria' => 'Reactivar'];
        }
        $this->panel->cambiarEstado($id, $n['activo_hasta'] ? 'activo' : 'prueba');
        $nuevo = $this->clasificar($this->fila($id))['estado'];
        return ['mensaje' => "{$n['nombre']} reactivado: queda " . self::ESTADOS_V2_TEXTO[$nuevo] . '.', 'resultado' => ['estado' => $nuevo],
                'auditoria' => 'Reactivado (' . ($n['activo_hasta'] ? 'activo' : 'prueba') . ')'];
    }

    // --- Anexo A: pausar, borrar (papelera) y restaurar ---

    private function opPausar(int $id, array $d): array
    {
        $tipo = $d['tipo'] ?? null;
        if (!is_string($tipo) || !isset(Papelera::TIPOS_PAUSA[$tipo])) {
            throw TukanCentralError::campo('datos.tipo', 'Elige el tipo de pausa: ' . implode(', ', array_keys(Papelera::TIPOS_PAUSA)) . '.');
        }
        $motivo = $this->textoReq($d, 'motivo', 3, 300);
        $n = $this->fila($id);
        if ($n['estado'] === 'cancelado') {
            throw new TukanCentralError('conflicto', "{$n['nombre']} está cancelado: reactívalo antes de pausarlo.", 409, null, ['motivo' => 'cancelado']);
        }
        $nombreTipo = Papelera::TIPOS_PAUSA[$tipo];
        $cambio = (new Papelera($this->db))->pausar($id, $tipo, $motivo);
        $res = ['estado' => 'suspendido', 'pausa' => ['tipo' => $tipo, 'motivo' => $motivo, 'texto_cliente' => Papelera::textoPausa($tipo, $motivo)]];
        if (!$cambio) {
            return ['mensaje' => "{$n['nombre']} ya estaba pausado así.", 'sin_cambios' => true, 'resultado' => $res, 'auditoria' => "Pausado ($nombreTipo): $motivo"];
        }
        return ['mensaje' => "{$n['nombre']} quedó pausado ($nombreTipo). No puede trabajar y su página de reservas dice \"temporalmente no disponible\". Sus datos se guardan.",
                'resultado' => $res, 'auditoria' => ($n['estado'] === 'suspendido' ? 'Pausa cambiada' : 'Pausado') . " ($nombreTipo): $motivo"];
    }

    private function opBorrar(int $id, array $d, array $p): array
    {
        $n = $this->fila($id);
        $confirmar = $this->texto($d, 'confirmar', 200, 'datos.');
        if ($confirmar === '' || ($confirmar !== trim((string) $n['nombre']) && $confirmar !== (string) $id)) {
            throw TukanCentralError::campo('datos.confirmar', 'Para borrar, escribe el nombre exacto del salón: ' . $n['nombre'] . '.');
        }
        $motivo = $this->textoReq($d, 'motivo', 3, 300);
        if (!empty($n['eliminado_en'])) {
            $hasta = Papelera::fechaPurga($n['eliminado_en']);
            return ['mensaje' => "{$n['nombre']} ya estaba en la papelera (se borra para siempre el " . self::fechaTexto($hasta) . ').', 'sin_cambios' => true,
                    'resultado' => ['copia' => $n['eliminado_copia'], 'borrar_definitivo_el' => $hasta],
                    'arriba' => ['copia' => $n['eliminado_copia'], 'borrar_definitivo_el' => $hasta], 'auditoria' => "Borrar: $motivo"];
        }
        $por = trim(($p['nombre'] ?: 'Panel Tukán Central') . ' <' . $p['email'] . '>');
        $c = (new Papelera($this->db))->borrar($id, $motivo, $por);
        $res = ['copia' => $c['ruta'], 'borrar_definitivo_el' => $c['borrar_definitivo_el'], 'tablas' => count($c['tablas']),
                'filas' => array_sum($c['tablas']), 'archivos' => $c['archivos']];
        return ['mensaje' => "{$n['nombre']} está en la papelera: ya no puede entrar y su página de reservas no existe. Se puede restaurar hasta el "
                             . self::fechaTexto((new DateTimeImmutable($c['borrar_definitivo_el']))->modify('-1 day')->format('Y-m-d'))
                             . '; el ' . self::fechaTexto($c['borrar_definitivo_el']) . ' se borra para siempre (la copia se guarda 1 año).',
                'resultado' => $res, 'arriba' => ['copia' => $c['ruta'], 'borrar_definitivo_el' => $c['borrar_definitivo_el']],
                'auditoria' => "Enviado a la papelera: $motivo · copia " . basename($c['ruta'])];
    }

    private function opRestaurar(int $id): array
    {
        $n = $this->fila($id);
        if (empty($n['eliminado_en'])) {
            return ['mensaje' => "{$n['nombre']} no está en la papelera.", 'sin_cambios' => true,
                    'resultado' => ['estado' => $this->clasificar($n)['estado']], 'auditoria' => 'Restaurar'];
        }
        if (Papelera::fechaPurga($n['eliminado_en']) <= $this->hoy->format('Y-m-d')) {
            throw new TukanCentralError('conflicto', 'Ya pasaron los ' . Papelera::DIAS_PAPELERA . ' días de la papelera: se está borrando para siempre.', 409, null,
                                        ['motivo' => 'papelera_vencida']);
        }
        (new Papelera($this->db))->restaurar($id);
        $estado = $this->clasificar($this->fila($id))['estado'];
        return ['mensaje' => "{$n['nombre']} salió de la papelera: queda " . self::ESTADOS_V2_TEXTO[$estado] . ', con todos sus datos.',
                'resultado' => ['estado' => $estado], 'auditoria' => 'Restaurado de la papelera'];
    }

    public const ESTADOS_V2_TEXTO = ['prueba' => 'en prueba', 'activo' => 'al día', 'por_vencer' => 'al día (vence pronto)', 'vencido' => 'vencido', 'suspendido' => 'suspendido'];

    private function opCambiarPlan(int $id, array $d): array
    {
        $plan = $d['plan'] ?? null;
        if (!is_string($plan) || !$this->planExiste($plan)) throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en TuSalón.');
        $n = $this->fila($id);
        if ($n['plan_codigo'] === $plan) {
            return ['mensaje' => "{$n['nombre']} ya tenía ese plan.", 'sin_cambios' => true, 'resultado' => ['plan' => $plan], 'auditoria' => "Plan $plan"];
        }
        $this->panel->cambiarPlan($id, $plan);
        return ['mensaje' => "Plan cambiado a $plan (sin cobrar).", 'resultado' => ['plan' => $plan], 'auditoria' => "Plan cambiado de {$n['plan_codigo']} a $plan"];
    }

    private function opNuevaClave(int $id): array
    {
        try {
            $r = $this->panel->nuevaClaveDueno($id);
        } catch (PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new TukanCentralError('no_permitido', $e->getMessage(), 403);
        }
        $primer = explode(' ', (string) $r['nombre'])[0];
        $msg = "Hola $primer, tu acceso a TuSalón: " . self::urlLogin() . " · Correo: {$r['email']} · Contraseña nueva: {$r['clave']} (cámbiala al entrar, en Mi cuenta)";
        return ['mensaje' => 'Contraseña nueva creada. La anterior ya no funciona.',
                'resultado' => ['usuario' => $r['email'], 'clave' => $r['clave'], 'mensaje_whatsapp' => $msg],
                'auditoria' => 'Nueva contraseña para el dueño'];
    }

    private function opNota(int $id, array $d): array
    {
        $texto = $d['texto'] ?? '';
        if (!is_string($texto) || mb_strlen($texto) > 4000) throw TukanCentralError::campo('datos.texto', 'La nota puede tener hasta 4000 letras.');
        $antes = (string) ($this->fila($id)['notas_admin'] ?? '');
        if (trim($texto) === trim($antes)) return ['mensaje' => 'La nota no cambió.', 'sin_cambios' => true, 'resultado' => ['notas_admin' => $antes], 'auditoria' => 'Notas internas'];
        $this->panel->guardarNotas($id, $texto);
        return ['mensaje' => trim($texto) === '' ? 'Nota borrada.' : 'Nota guardada.', 'resultado' => ['notas_admin' => mb_substr(trim($texto), 0, 4000)],
                'auditoria' => 'Notas internas actualizadas'];
    }

    private function opAtender(array $d, array $p): array
    {
        $sid = $d['solicitud_id'] ?? null;
        if (!is_string($sid) || !preg_match(self::ID, $sid)) throw TukanCentralError::campo('datos.solicitud_id', 'Falta la solicitud.');
        $resultado = $d['resultado'] ?? null;
        if (!is_string($resultado) || !in_array($resultado, self::RESULTADOS, true)) {
            throw TukanCentralError::campo('datos.resultado', 'Elige el resultado: en proceso, atendida o descartada.');
        }
        $nota = $this->texto($d, 'nota', 300, 'datos.');
        $dominio = strtolower($this->texto($d, 'dominio', 120, 'datos.'));
        $s = $this->solicitud(['id' => $sid]);
        if (in_array($s['tipo'], ['demo_pedida', 'cambio_modulos'], true)) return $this->atenderPedido($s, $resultado, $nota, $dominio);
        $id = (int) $s['cliente_id'];
        $this->bloquear($id);
        $actual = $s['estado'];
        $guardarDominio = $s['tipo'] === 'dominio_gratis' && $dominio !== '';
        if ($actual === $resultado && !$guardarDominio) {
            return ['cliente_id' => $id, 'mensaje' => 'La solicitud ya estaba así.', 'sin_cambios' => true, 'resultado' => ['solicitud' => $s], 'solicitud' => $s,
                    'auditoria' => "Solicitud {$s['id']}: $resultado"];
        }
        if (in_array($actual, ['atendida', 'descartada'], true) && $actual !== $resultado) {
            throw new TukanCentralError('conflicto', 'Esta solicitud ya fue ' . ($actual === 'atendida' ? 'atendida' : 'descartada') . '.', 409, null,
                                        ['motivo' => 'ya_atendida', 'estado' => $actual]);
        }
        if ($dominio !== '' && $s['tipo'] !== 'dominio_gratis') throw TukanCentralError::campo('datos.dominio', 'El dominio solo va en un dominio gratis.');
        if ($guardarDominio) {
            if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $dominio)) throw TukanCentralError::campo('datos.dominio', 'El dominio no es válido (ej. barberiaelcorte.com).');
            $this->db->prepare('UPDATE suscripciones SET dominio = ? WHERE id = ? AND dominio IS NULL')->execute([$dominio, (int) $s['detalle']['pago_id']]);
            if ($nota === '') $nota = "Dominio: $dominio";
        }
        $this->db->prepare('INSERT INTO tukan_central_atenciones (solicitud_id, tipo, estado, nota, email, nombre, momento) VALUES (?,?,?,?,?,?, now())
                            ON CONFLICT (solicitud_id) DO UPDATE SET estado = EXCLUDED.estado, nota = EXCLUDED.nota, email = EXCLUDED.email,
                                                                     nombre = EXCLUDED.nombre, momento = now()')
                 ->execute([$sid, $s['tipo'], $resultado, $nota !== '' ? $nota : null, $p['email'], $p['nombre'] ?: null]);
        $s['estado'] = $s['estado_producto'] = $resultado;
        $s['actualizado'] = (new DateTimeImmutable('now'))->format('c');
        $s['detalle']['atencion'] = ['estado' => $resultado, 'nota' => $nota !== '' ? $nota : null, 'por' => trim($p['nombre'] . ' <' . $p['email'] . '>'),
                                     'momento' => $s['actualizado']];
        $texto = ['en_proceso' => 'en proceso', 'atendida' => 'atendida', 'descartada' => 'descartada'][$resultado];
        return ['cliente_id' => $id, 'mensaje' => "Solicitud marcada como $texto." . ($guardarDominio ? " Dominio $dominio guardado." : ''),
                'resultado' => ['solicitud' => $s], 'solicitud' => $s,
                'auditoria' => "Solicitud «{$s['titulo']}» marcada como $texto" . ($nota !== '' ? " · $nota" : '')];
    }

    private function opGuardarPlan(array $d): array
    {
        $codigo = $d['plan'] ?? null;
        if (!is_string($codigo) || !$this->planExiste($codigo)) throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en TuSalón.');
        $st = $this->db->prepare('SELECT * FROM planes WHERE codigo = ?');
        $st->execute([$codigo]);
        $p = $st->fetch();
        $nombre = array_key_exists('nombre', $d) ? $this->textoReq($d, 'nombre', 2, 50) : (string) $p['nombre'];
        $precio = $this->dineroOpc($d, 'precio_mensual', 'datos.');
        if ($precio === null && isset($d['precios'])) {
            if (!is_array($d['precios']) || !array_is_list($d['precios'])) throw TukanCentralError::campo('datos.precios', 'Los precios no son válidos.');
            foreach ($d['precios'] as $i => $x) {
                if (is_array($x) && ($x['periodo'] ?? null) === 'mensual') $precio = $this->dineroOpc($x, 'precio', "datos.precios.$i.");
            }
        }
        $precio ??= (float) $p['precio_mensual'];
        $descripcion = array_key_exists('descripcion', $d) ? $this->texto($d, 'descripcion', 300, 'datos.') : (string) ($p['descripcion'] ?? '');
        $max = function (string $k) use ($d, $p): ?int {
            if (!array_key_exists($k, $d)) return $p[$k] === null ? null : (int) $p[$k];
            return $d[$k] === null || $d[$k] === '' ? null : $this->entero($d, $k, null, 1, 100000, 'datos.');
        };
        $this->planes->guardarPlan($codigo, $nombre, $precio, $max('max_profesionales'), $descripcion);
        if (isset($d['ajustes'])) {
            if (!is_array($d['ajustes']) || array_is_list($d['ajustes'])) throw TukanCentralError::campo('datos.ajustes', 'Los ajustes no son válidos.');
            $this->planes->guardarAjustes(array_map('strval', array_merge($this->planes->ajustes(), $d['ajustes'])));
        }
        $st->execute([$codigo]);
        $cuantos = (int) $this->db->query('SELECT count(*) FROM salones WHERE eliminado_en IS NULL AND plan_codigo = ' . $this->db->quote($codigo))->fetchColumn();
        return ['mensaje' => "Plan $nombre guardado (" . self::dinero($precio) . ' al mes).', 'resultado' => ['plan' => $this->plan($st->fetch(), $cuantos)],
                'auditoria' => "Plan $codigo guardado: $nombre " . self::dinero($precio) . ' al mes'];
    }

    // ------------------------------------------------------------------
    // Anexo B: módulos (lectura "modulos", cliente.modulos, cambiar_modulos, guardar_modulos, solicitudes cambio_modulos)
    // ------------------------------------------------------------------

    private function detalleModulos(array $n, ?string $plan = null): array
    {
        return $this->modulos->detalle($n, $plan);
    }

    /** cliente.modulos (§B.2): del_plan, extra, quitados, activos, precio_plan, precio_extra, descuento, precio_acordado, mensual (+ demo). */
    private function modulosCliente(array $n, ?string $plan = null): array
    {
        $x = $this->detalleModulos($n, $plan);
        return ['del_plan' => $x['del_plan'], 'extra' => $x['extra'], 'quitados' => $x['quitados'], 'activos' => $x['activos'],
                'precio_plan' => $x['precio_plan'], 'precio_extra' => $x['precio_extra'], 'descuento' => $x['descuento'],
                'precio_acordado' => $x['precio_acordado'], 'mensual' => $x['mensual'],
                'demo' => $x['demo'], 'contratados' => $x['contratados']];
    }

    /** Acción de lectura "modulos": la base, el catálogo (con precios editables) y los módulos de cada plan. */
    public function modulosV2(): array
    {
        $grupos = [];
        foreach (Modulos::GRUPOS as $g => $nombre) $grupos[] = ['grupo' => $g, 'nombre' => $nombre];
        $planes = [];
        foreach ($this->modulos->planes() as $p) {
            $planes[] = ['codigo' => (string) $p['codigo'], 'nombre' => (string) $p['nombre'], 'precio_mensual' => (float) $p['precio_mensual'],
                         'modulos' => $p['modulos'], 'max_profesionales' => $p['max_profesionales'] === null ? null : (int) $p['max_profesionales']];
        }
        $mods = [];
        foreach ($this->modulos->catalogo() as $m) {
            $mods[] = ['id' => $m['id'], 'nombre' => $m['nombre'], 'icono' => $m['icono'], 'descripcion' => $m['descripcion'],
                       'precio_mensual' => $m['precio_mensual'], 'descuento' => $m['descuento'], 'depende_de' => $m['depende_de'],
                       'grupo' => $m['grupo'], 'grupo_nombre' => $m['grupo_nombre'], 'orden' => $m['orden']];
        }
        return ['base' => Modulos::BASE, 'grupos' => $grupos, 'modulos' => $mods, 'planes' => $planes, 'editable' => true,
                'personas_sin_modulo' => Modulos::PERSONAS_SIN_MODULO,
                'reglas' => 'Activos = (módulos del plan − quitados) + extra, con sus dependencias. Demo: todos (o los elegidos al crearla). '
                          . 'Mensual = precio del plan + Σ precio de extra − Σ descuento de quitados, o el precio acordado. Apagar no borra datos.'];
    }

    private static function campoModulosDemo(): array
    {
        return ['campo' => 'modulos', 'etiqueta' => 'Módulos de la demo (vacío = todos)', 'tipo' => 'texto', 'requerido' => false, 'max' => 400,
                'ayuda' => 'Ids separados por coma (o "ninguno" = solo la base): ' . implode(', ', Modulos::ids()) . '.'];
    }

    private function camposModulos(?array $n): array
    {
        $x = $n ? $this->detalleModulos($n) : null;
        $ids = implode(', ', Modulos::ids());
        return [
            ['campo' => 'encender', 'etiqueta' => 'Encender (ids separados por coma)', 'tipo' => 'texto', 'requerido' => false, 'max' => 400,
             'ayuda' => 'Se cobran aparte si no son del plan. Encender uno enciende lo que necesita. Ids: ' . $ids . '.'],
            ['campo' => 'apagar', 'etiqueta' => 'Apagar (ids separados por coma)', 'tipo' => 'texto', 'requerido' => false, 'max' => 400,
             'ayuda' => 'Apagar no borra datos. Activos hoy: ' . ($x ? (implode(', ', $x['activos']) ?: 'solo la base') : '—') . '.'],
            ['campo' => 'precio_acordado', 'etiqueta' => 'Precio acordado al mes ($, vacío = el calculado)', 'tipo' => 'dinero', 'requerido' => false, 'min' => 0,
             'valor' => $x['precio_acordado'] ?? null, 'ayuda' => $x ? 'Calculado: $' . number_format($x['precio_calculado'], 2, ',', '.') . ' al mes.' : null],
            ['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => false, 'max' => 300],
        ];
    }

    /** Lista de ids de módulos (array o "a,b"). null si no vino o vino vacía como texto. Un id que no existe → 422 con $campo. */
    private function listaModulos(mixed $v, string $campo): ?array
    {
        if ($v === null || (is_string($v) && trim($v) === '')) return null;
        if (is_string($v) && strtolower(trim($v)) === Modulos::NINGUNO) return [];
        if (is_string($v)) $v = array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
        if (!is_array($v) || !array_is_list($v) || count($v) > 50) throw TukanCentralError::campo($campo, 'La lista de módulos no es válida.');
        foreach ($v as $x) {
            if (!is_string($x) || !Modulos::existe($x)) {
                throw TukanCentralError::campo($campo, 'Ese módulo no existe en TuSalón: ' . mb_substr(is_scalar($x) ? (string) $x : '?', 0, 40) . '. Módulos: ' . implode(', ', Modulos::ids()) . '.');
            }
        }
        return Modulos::limpiar($v);
    }

    private function solicitudDe(string $id): ?array
    {
        $this->solicitudesCache = null;
        foreach ($this->todasLasSolicitudes() as $s) if ($s['id'] === $id) return $s;
        return null;
    }

    /** Pedidos del formulario público "Solicitar demo" (los abiertos y los cerrados de los últimos 120 días). */
    private function solicitudesDemo(): array
    {
        $r = [];
        foreach ((new PedidosDemo($this->db))->lista(true) as $p) {
            $wa = WhatsApp::normalizarTelefono($p['whatsapp']);
            $mods = Modulos::limpiar($p['modulos']);
            $nombres = array_map([$this->modulos, 'nombre'], $mods);
            $id = 'demo_pedida:' . $p['id'];
            $abierta = in_array($p['estado'], ['nueva', 'contactada'], true);
            $primer = explode(' ', trim((string) $p['nombre']))[0];
            $texto = "Hola $primer, te escribo de TuSalón por tu pedido de demo para {$p['salon']}. ¿Cuándo te viene bien que te la mostremos?";
            $a = [];
            if ($wa) $a[] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $wa, 'texto' => $texto];
            if ($abierta) {
                $a[] = ['accion' => 'crear_demo', 'etiqueta' => 'Crear demo', 'estilo' => 'principal', 'confirmar' => '¿Crear la demo de ' . $p['salon'] . '?',
                        'datos' => ['solicitud_id' => $id],
                        'campos' => [
                            ['campo' => 'nombre', 'etiqueta' => 'Nombre del salón', 'tipo' => 'texto', 'requerido' => true, 'valor' => $p['salon'], 'min' => 3, 'max' => 120],
                            ['campo' => 'contacto_nombre', 'etiqueta' => 'Nombre del dueño', 'tipo' => 'texto', 'requerido' => true, 'valor' => $p['nombre'], 'min' => 2, 'max' => 80],
                            ['campo' => 'email', 'etiqueta' => 'Correo (será su usuario)', 'tipo' => 'email', 'requerido' => true, 'valor' => $p['email']],
                            ['campo' => 'telefono', 'etiqueta' => 'Teléfono', 'tipo' => 'telefono', 'requerido' => true, 'valor' => $p['whatsapp']],
                            ['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'completa', 'opciones' => $this->opcionesPlan()],
                            ['campo' => 'dias', 'etiqueta' => 'Días de demo', 'tipo' => 'dias', 'requerido' => true, 'valor' => max(1, (int) $this->planes->ajuste('dias_prueba')), 'min' => 1, 'max' => 90],
                            self::campoModulosDemo(),
                        ]];
                $a[] = ['accion' => 'atender_solicitud', 'etiqueta' => 'Marcar', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['solicitud_id' => $id],
                        'campos' => [['campo' => 'resultado', 'etiqueta' => 'Resultado', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'en_proceso',
                                      'opciones' => [['valor' => 'en_proceso', 'etiqueta' => 'Contactado'], ['valor' => 'descartada', 'etiqueta' => 'Descartar']]],
                                     ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 300]]];
            }
            if ($p['salon_creado_id']) $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver la demo en TuSalón', 'ir' => '/?r=admin_salon&id=' . (int) $p['salon_creado_id']];
            $r[] = [
                'id' => $id, 'tipo' => 'demo_pedida', 'categoria' => 'pedido', 'titulo' => 'Pedido de demo',
                'fecha' => self::momento($p['creado']), 'actualizado' => self::momento($p['actualizado']),
                'cliente_id' => $p['salon_creado_id'] ? (string) $p['salon_creado_id'] : null,
                'quien' => ['nombre' => $p['nombre'], 'organizacion' => $p['salon'], 'telefono' => $p['whatsapp'], 'whatsapp' => $wa, 'email' => $p['email']],
                'resumen' => trim(($p['ciudad'] ?: '') . ($nombres ? ' · Módulos: ' . implode(', ', $nombres) : '') . ($p['necesita'] ? ' · ' . mb_substr($p['necesita'], 0, 120) : ''), ' ·'),
                'monto' => null, 'estado' => self::ESTADOS_DEMO[$p['estado']], 'estado_producto' => $p['estado'],
                'prioridad' => $p['estado'] === 'nueva' ? 'alta' : 'normal', 'vence_el' => null,
                'detalle' => ['ciudad' => $p['ciudad'], 'necesita' => $p['necesita'], 'modulos' => $mods, 'modulos_nombres' => $nombres,
                              'salon_creado_id' => $p['salon_creado_id'] ? (string) $p['salon_creado_id'] : null,
                              'atencion' => $p['atendido_por'] ? ['estado' => $p['estado'], 'nota' => $p['nota'], 'por' => $p['atendido_por'], 'momento' => self::momento($p['actualizado'])] : null],
                'adjuntos' => [], 'acciones_posibles' => $a, 'ir' => '/?r=admin#pedidos-demo',
            ];
        }
        return $r;
    }

    /** "Pedir este módulo" desde Mi plan (pedidos_modulos): aprobar = cambiar_modulos, rechazar = atender_solicitud descartada. */
    private function solicitudesModulos(array $filas): array
    {
        $r = [];
        $st = $this->db->query("SELECT p.*, u.nombre AS pedido_por FROM pedidos_modulos p LEFT JOIN usuarios u ON u.id = p.usuario_id
                                 WHERE p.estado IN ('pendiente','en_proceso') OR p.actualizado > now() - interval '120 days' ORDER BY p.id");
        foreach ($st->fetchAll() as $p) {
            $n = $filas[(int) $p['salon_id']] ?? null;
            if (!$n) continue;
            $mods = Modulos::limpiar($p['modulos']);
            $cat = $this->modulos->catalogo();
            $antes = $this->detalleModulos($n);
            $faltan = array_values(array_diff(Modulos::conDependencias($mods), $antes['contratados']));
            $suma = round(array_sum(array_map(fn($m) => in_array($m, $antes['quitados'], true) ? $cat[$m]['descuento'] : $cat[$m]['precio_mensual'], $faltan)), 2);
            $nuevo = $antes['precio_acordado'] ?? round($antes['precio_calculado'] + $suma, 2);
            $nombres = array_map(fn($m) => $cat[$m]['nombre'], $mods);
            $id = 'cambio_modulos:' . $p['id'];
            $abierta = in_array($p['estado'], ['pendiente', 'en_proceso'], true);
            $c = $this->clasificar($n);
            $tel = $n['telefono'] ?: null;
            $wa = WhatsApp::normalizarTelefono($tel);
            $datos = ['cliente_id' => (string) $n['id'], 'solicitud_id' => $id];
            $a = [];
            if ($abierta) {
                $a[] = ['accion' => 'cambiar_modulos', 'etiqueta' => 'Aprobar y encender', 'estilo' => 'principal',
                        'confirmar' => '¿Encender ' . implode(', ', $nombres) . ' en ' . $n['nombre'] . '?' . ($antes['precio_acordado'] === null ? ' Pasa a pagar ' . self::dinero($nuevo) . ' al mes.' : ''),
                        'datos' => $datos + ['encender' => $mods],
                        'campos' => [['campo' => 'precio_acordado', 'etiqueta' => 'Precio acordado al mes ($, vacío = el calculado)', 'tipo' => 'dinero', 'requerido' => false, 'min' => 0,
                                      'valor' => $antes['precio_acordado']],
                                     ['campo' => 'motivo', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 300, 'valor' => 'Pedido desde Mi plan']]];
                $a[] = ['accion' => 'atender_solicitud', 'etiqueta' => 'Rechazar', 'estilo' => 'peligro', 'confirmar' => null,
                        'datos' => ['solicitud_id' => $id, 'resultado' => 'descartada'],
                        'campos' => [['campo' => 'nota', 'etiqueta' => 'Motivo (lo verá el salón en Mi plan)', 'tipo' => 'texto', 'requerido' => false, 'max' => 300]]];
                if ($wa) $a[] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $wa,
                                 'texto' => 'Hola, te escribo de TuSalón por tu pedido de ' . implode(', ', $nombres) . ' para ' . $n['nombre'] . '.'];
            }
            $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuSalón', 'ir' => '/?r=admin_salon&id=' . (int) $n['id'] . '#modulos'];
            $r[] = [
                'id' => $id, 'tipo' => 'cambio_modulos', 'categoria' => 'pedido', 'titulo' => 'Pide módulos: ' . implode(', ', $nombres),
                'fecha' => self::momento($p['creado']), 'actualizado' => self::momento($p['actualizado']), 'cliente_id' => (string) $n['id'],
                'quien' => ['nombre' => $p['pedido_por'] ?: ($n['dueno'] ?: null), 'organizacion' => (string) $n['nombre'], 'telefono' => $tel, 'whatsapp' => $wa,
                            'email' => $n['dueno_email'] ?: null],
                'resumen' => implode(', ', array_map(fn($m) => $cat[$m]['nombre'] . ' (' . self::dinero($cat[$m]['precio_mensual']) . ')', $mods))
                             . ' · hoy paga ' . self::dinero($antes['mensual']) . ', pasaría a ' . self::dinero($nuevo) . ' al mes' . ($p['nota'] ? ' · ' . $p['nota'] : ''),
                'monto' => $suma, 'estado' => $p['estado'], 'estado_producto' => $p['estado'], 'prioridad' => 'normal', 'vence_el' => null,
                'detalle' => ['modulos' => $mods, 'modulos_nombres' => $nombres, 'faltan' => $faltan, 'precio_actual' => $antes['mensual'], 'precio_nuevo' => $nuevo,
                              'nota' => $p['nota'], 'pedido_por' => $p['pedido_por'], 'plan' => ['codigo' => (string) $n['plan_codigo'], 'nombre' => (string) $n['plan_nombre']],
                              'estado_cliente' => $c['estado'],
                              'atencion' => $p['atendido_por'] ? ['estado' => $p['estado'], 'nota' => $p['respuesta'], 'por' => $p['atendido_por'], 'momento' => self::momento($p['actualizado'])] : null],
                'adjuntos' => [], 'acciones_posibles' => $a, 'ir' => '/?r=admin_salon&id=' . (int) $n['id'] . '#modulos',
            ];
        }
        return $r;
    }

    /** atender_solicitud de un pedido de demo o de módulos (tienen su propia tabla). */
    private function atenderPedido(array $s, string $resultado, string $nota, string $dominio): array
    {
        if ($dominio !== '') throw TukanCentralError::campo('datos.dominio', 'El dominio solo va en un dominio por registrar.');
        $esDemo = $s['tipo'] === 'demo_pedida';
        if ($resultado === 'atendida') {
            throw TukanCentralError::campo('datos.resultado', $esDemo ? 'Para atender un pedido de demo, crea la demo (crear_demo) o descártalo.'
                                                                    : 'Para aprobar el pedido, usa cambiar_modulos (Aprobar y encender), o descártalo.');
        }
        $num = (int) substr($s['id'], strpos($s['id'], ':') + 1);
        $clienteId = null;
        if ($esDemo) {
            $st = $this->db->prepare('SELECT estado FROM pedidos_demo WHERE id = ? FOR UPDATE');
        } else {
            $clienteId = (int) $s['cliente_id'];
            $this->bloquear($clienteId);
            $st = $this->db->prepare('SELECT estado FROM pedidos_modulos WHERE id = ? FOR UPDATE');
        }
        $st->execute([$num]);
        $actual = (string) $st->fetchColumn();
        $nuevo = $esDemo ? ($resultado === 'en_proceso' ? 'contactada' : 'descartada') : $resultado;
        $generico = $esDemo ? (self::ESTADOS_DEMO[$actual] ?? $actual) : $actual;
        if ($generico === $resultado) {
            return ['cliente_id' => $clienteId, 'mensaje' => 'La solicitud ya estaba así.', 'sin_cambios' => true, 'resultado' => ['solicitud' => $s], 'solicitud' => $s,
                    'auditoria' => "Solicitud {$s['id']}: $resultado"];
        }
        if (in_array($generico, ['atendida', 'descartada'], true)) {
            throw new TukanCentralError('conflicto', 'Esta solicitud ya fue ' . ($generico === 'atendida' ? 'atendida' : 'descartada') . '.', 409, null,
                                        ['motivo' => 'ya_atendida', 'estado' => $generico]);
        }
        if ($esDemo) (new PedidosDemo($this->db))->marcar($num, $nuevo, (string) $this->quien, $nota);
        else $this->modulos->cerrarPedido($num, $nuevo, (string) $this->quien, $nota);
        $s2 = $this->solicitudDe($s['id']) ?? $s;
        $texto = ['en_proceso' => $esDemo ? 'contactada' : 'en proceso', 'descartada' => 'descartada'][$resultado];
        return ['cliente_id' => $clienteId, 'mensaje' => "Solicitud marcada como $texto.", 'resultado' => ['solicitud' => $s2], 'solicitud' => $s2,
                'auditoria' => "Solicitud «{$s['titulo']}» marcada como $texto" . ($nota !== '' ? " · $nota" : '')];
    }

    private function opCambiarModulos(int $id, array $d, array $p): array
    {
        $encender = $this->listaModulos($d['encender'] ?? null, 'datos.encender') ?? [];
        $apagar = $this->listaModulos($d['apagar'] ?? null, 'datos.apagar') ?? [];
        $acordado = false;
        if (array_key_exists('precio_acordado', $d)) $acordado = $this->dineroOpc($d, 'precio_acordado', 'datos.');
        $motivo = $this->texto($d, 'motivo', 300, 'datos.');
        $pedido = null;
        $sol = $d['solicitud_id'] ?? null;
        if ($sol !== null && $sol !== '') {
            if (!is_string($sol) || !preg_match('/^cambio_modulos:(\d{1,10})$/', $sol, $mm)) throw TukanCentralError::campo('datos.solicitud_id', 'La solicitud debe ser un pedido de módulos (cambio_modulos:N).');
            $pedido = $this->modulos->pedido((int) $mm[1]);
            if (!$pedido || (int) $pedido['salon_id'] !== $id) throw new TukanCentralError('no_encontrado', 'Ese pedido de módulos no es de este salón.', 404);
            if (!in_array($pedido['estado'], ['pendiente', 'en_proceso'], true)) {
                throw new TukanCentralError('conflicto', 'Ese pedido ya fue ' . ($pedido['estado'] === 'atendida' ? 'aprobado' : 'descartado') . '.', 409, null,
                                            ['motivo' => 'ya_atendida', 'estado' => $pedido['estado']]);
            }
            if (!$encender && !$apagar) $encender = Modulos::limpiar($pedido['modulos']);
        }
        try {
            $r = $this->modulos->cambiar($id, $encender, $apagar, $acordado, $motivo, (string) $this->quien, 'central');
        } catch (PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new TukanCentralError('invalido', $e->getMessage(), 422, str_contains($e->getMessage(), 'apagar') ? 'datos.apagar' : null);
        }
        if ($pedido) $this->modulos->cerrarPedido((int) $pedido['id'], 'atendida', (string) $this->quien, $motivo ?: 'Aprobado');
        $n = $this->fila($id);
        $mod = $this->modulosCliente($n);
        $texto = $this->modulos->textoCambio($r);
        $sinCambios = $r['sin_cambios'] && !$pedido;
        return ['mensaje' => $sinCambios ? "Los módulos de {$n['nombre']} ya estaban así." : "Módulos de {$n['nombre']} guardados. $texto. Paga " . self::dinero($mod['mensual']) . ' al mes.',
                'sin_cambios' => $sinCambios,
                'resultado' => ['modulos' => $mod, 'encendidos' => $r['encendidos'], 'apagados' => $r['apagados'], 'por_dependencia' => $r['por_dependencia']],
                'solicitud' => $pedido ? $this->solicitudDe('cambio_modulos:' . $pedido['id']) : null,
                'auditoria' => "Módulos: $texto" . ($motivo !== '' ? " · $motivo" : '') . ($pedido ? ' · pedido N.º ' . $pedido['id'] : '')];
    }

    private function opGuardarModulos(array $d): array
    {
        $mods = $d['modulos'] ?? [];
        if (!is_array($mods)) throw TukanCentralError::campo('datos.modulos', 'Los módulos no son válidos.');
        if (array_is_list($mods)) {
            $mapa = [];
            foreach ($mods as $i => $m) {
                if (!is_array($m) || !is_string($m['id'] ?? null)) throw TukanCentralError::campo("datos.modulos.$i.id", 'Falta el id del módulo.');
                $mapa[$m['id']] = $m;
            }
            $mods = $mapa;
        }
        $planes = $d['planes'] ?? [];
        if (!is_array($planes) || ($planes !== [] && array_is_list($planes))) throw TukanCentralError::campo('datos.planes', 'Los planes no son válidos (codigo => [ids]).');
        foreach ($planes as $codigo => $lista) {
            if (is_string($lista) && $lista !== '*') $planes[$codigo] = $this->listaModulos($lista, "datos.planes.$codigo") ?? [];
            elseif (is_array($lista) && $lista !== ['*']) $planes[$codigo] = $this->listaModulos($lista, "datos.planes.$codigo") ?? [];
        }
        $cambios = $this->modulos->guardarCatalogo($mods, $planes);
        return ['mensaje' => $cambios ? 'Catálogo de módulos guardado: ' . implode('; ', $cambios) . '.' : 'El catálogo no cambió.',
                'sin_cambios' => !$cambios, 'resultado' => $this->modulosV2(),
                'auditoria' => $cambios ? 'Catálogo de módulos: ' . implode('; ', $cambios) : 'Catálogo de módulos (sin cambios)'];
    }

    // ------------------------------------------------------------------
    // Validación de campos (errores 422 con "campo")
    // ------------------------------------------------------------------

    private function idCliente(mixed $v, string $campo): int
    {
        if (!(is_int($v) || (is_string($v) && preg_match(self::ID, $v)))) throw TukanCentralError::campo($campo, 'Falta el cliente.');
        if (!preg_match('/^\d{1,10}$/', (string) $v) || !$this->fila((int) $v)) {
            throw new TukanCentralError('no_encontrado', 'Ese salón ya no existe en TuSalón.', 404);
        }
        return (int) $v;
    }

    private function entero(array $d, string $k, ?int $defecto, int $min, int $max, string $ruta = ''): int
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') {
            if ($defecto === null) throw TukanCentralError::campo($ruta . $k, "Falta el valor de \"$k\".");
            return $defecto;
        }
        if (is_float($v) && floor($v) === $v) $v = (int) $v;
        if (is_string($v) && preg_match('/^-?\d{1,9}$/', $v)) $v = (int) $v;
        if (!is_int($v) || $v < $min || $v > $max) throw TukanCentralError::campo($ruta . $k, "\"$k\" debe ser un número de $min a $max.");
        return $v;
    }

    private function texto(array $d, string $k, int $max, string $ruta = ''): string
    {
        $v = $d[$k] ?? null;
        if ($v === null) return '';
        if (!is_string($v)) throw TukanCentralError::campo($ruta . $k, "\"$k\" debe ser texto.");
        $v = trim($v);
        if (mb_strlen($v) > $max) throw TukanCentralError::campo($ruta . $k, "\"$k\" puede tener hasta $max letras.");
        return $v;
    }

    private function textoReq(array $d, string $k, int $min, int $max): string
    {
        $v = $this->texto($d, $k, $max, 'datos.');
        if (mb_strlen($v) < $min) throw TukanCentralError::campo('datos.' . $k, "Escribe \"$k\" (de $min a $max letras).");
        return $v;
    }

    private function lista(array $d, string $k, array $permitidos): array
    {
        $v = $d[$k] ?? [];
        if (!is_array($v) || !array_is_list($v) || array_filter($v, fn($x) => !is_string($x) || !in_array($x, $permitidos, true))) {
            throw TukanCentralError::campo($k, "\"$k\" debe ser una lista con: " . implode(', ', $permitidos) . '.');
        }
        return $v;
    }

    private function opcion(array $d, string $k, array $permitidos, string $defecto): string
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') return $defecto;
        if (!is_string($v) || !in_array($v, $permitidos, true)) throw TukanCentralError::campo($k, "\"$k\" debe ser uno de: " . implode(', ', $permitidos) . '.');
        return $v;
    }

    private function fecha(array $d, string $k, string $ruta = ''): ?string
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') return null;
        $f = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        if (!$f || $f->format('Y-m-d') !== $v) throw TukanCentralError::campo($ruta . $k, "\"$k\" debe ser una fecha como 2026-10-13.");
        return $v;
    }

    private function mes(array $d, string $k): ?string
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') return null;
        $f = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m', $v) : false;
        if (!$f || $f->format('Y-m') !== $v) throw TukanCentralError::campo($k, "\"$k\" debe ser un mes como 2026-10.");
        return $v;
    }

    private function dineroOpc(array $d, string $k, string $ruta = ''): ?float
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') return null;
        if (!(is_int($v) || is_float($v))) throw TukanCentralError::campo($ruta . $k, 'Escribe el monto con números, por ejemplo 39.00.');
        if ($v < 0 || $v > 100000) throw TukanCentralError::campo($ruta . $k, 'El monto debe estar entre 0 y 100.000.');
        return round((float) $v, 2);
    }

    // ------------------------------------------------------------------
    // Ayudas: fechas, dinero y direcciones
    // ------------------------------------------------------------------

    private function diasHasta(string $fecha): int
    {
        return (int) $this->hoy->diff(new DateTimeImmutable(substr($fecha, 0, 10)))->format('%r%a');
    }

    public static function momento(?string $ts): ?string
    {
        return $ts ? (new DateTimeImmutable($ts))->format('c') : null;
    }

    private static function fechaTexto(?string $f): string
    {
        return $f ? (new DateTimeImmutable($f))->format('d/m/Y') : '';
    }

    private static function fechaCorta(?string $f): string
    {
        return $f ? (new DateTimeImmutable($f))->format('d/m') : '';
    }

    private static function dias(int $n): string
    {
        return $n === 1 ? '1 día' : "$n días";
    }

    private static function dinero(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    /** Dirección pública de TuSalón (la misma de Agenda::urlBase(): TUSALON_URL o el dominio del pedido). */
    public static function urlBase(): string
    {
        return Agenda::urlBase();
    }

    public static function urlLogin(): string
    {
        return self::urlBase() . '/?r=login';
    }

    /** Página pública del salón: sus reservas en línea (la que muestra la ficha del panel de Tukán). */
    public static function urlSitio(string $slug): string
    {
        return self::urlBase() . '/?r=reservar&s=' . rawurlencode($slug);
    }

    /** Contraseña de 10 letras y números para el dueño (como "Nuevo salón" y nuevaClaveDueno()). */
    private static function claveNueva(): string
    {
        return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 10);
    }

    private static function nombreMetodo(string $m): string
    {
        return $m === 'google_play' ? 'Google Play' : (PanelTukan::METODOS[$m] ?? $m);
    }
}
