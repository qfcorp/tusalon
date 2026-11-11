<?php
declare(strict_types=1);

/**
 * «Pagar mi plan con tarjeta» desde Mi plan — módulo común de la familia TuSalón (10 nov 2026). Pedido del dueño de Tukán:
 * «¿el cliente puede solicitar y pagar desde su mismo panel, ya sea de demo… o debo hacerlo yo?». Con esto paga solo.
 * El archivo es IGUAL en los cinco programas de la familia de TuSalón (si se cambia en uno, se copia a los demás).
 *
 * Cómo funciona:
 *   - El programa NO habla con Plux. Le pide el cobro al Panel Tukán Central (plux_api.php), firmando igual que
 *     «Cancelar mi plan» (se usa TukanCancelarPlan::llamarPanel: mismo token v2 «plux» y misma configuración).
 *   - «estado»: el Panel dice si el pago con tarjeta está encendido para este programa («activo»), si está en modo de
 *     prueba y los cobros de este cliente. Si el Panel no contesta o está apagado, Mi plan queda como siempre.
 *   - «crear»: suscripción (se cobra sola cada período) o pago de una sola vez, con el valor SIN IVA que ya calcula el
 *     programa (plan + módulos extra, o el precio acordado). El IVA lo suma Tukán Pagos. Devuelve la página de pago.
 *   - Cuando Plux aprueba, el Panel registra el pago aquí con «registrar_pago» (conector v2): la cuenta queda activa con el
 *     plan y el período pagados (también si era una demo) y Tukán emite la factura. Aquí no se programa nada de eso.
 *   - «cambiar»: si dentro del programa cambian los módulos o el plan de quien tiene suscripción viva, se manda el valor nuevo;
 *     el Panel sube los próximos cobros y cobra aparte lo proporcional. No puede bajar durante el período pagado (cambiarCuidando).
 *   - En la app de Google Play no se muestra nada de esto (lo decide la página con en_app_play()).
 */
final class TukanPagoPlan
{
    /** Estados en que una suscripción con tarjeta sigue viva (no se ofrece crear otra: el Panel contesta 409). */
    public const VIVAS = ['pendiente', 'rechazado', 'activa', 'en_gracia'];
    /** Cada cuánto se cobra la suscripción, en palabras. */
    public const CADA = ['mensual' => 'cada mes', 'semestral' => 'cada 6 meses', 'anual' => 'cada año'];

    /** @var array<string, ?array> */
    private static array $cache = [];

    /** Lo que dice el Panel de los pagos con tarjeta de este cliente (una vez por página). null = no contesta o no está conectado. */
    public static function estado(string $clienteId, string $email = '', bool $deNuevo = false): ?array
    {
        if ($deNuevo || !array_key_exists($clienteId, self::$cache)) {
            [$http, $j] = TukanCancelarPlan::llamarPanel(['accion' => 'estado', 'cliente_id' => $clienteId], $email);
            self::$cache[$clienteId] = $http === 200 && is_array($j) && !empty($j['ok']) && array_key_exists('activo', $j) ? $j : null;
        }
        return self::$cache[$clienteId];
    }

    /** ¿El Panel tiene encendido el pago con tarjeta para este programa? */
    public static function activo(?array $estado): bool
    {
        return $estado !== null && !empty($estado['activo']);
    }

    /** La suscripción con tarjeta viva del cliente (o null). */
    public static function suscripcion(?array $estado): ?array
    {
        foreach (is_array($estado['cobros'] ?? null) ? $estado['cobros'] : [] as $c) {
            if (is_array($c) && ($c['tipo'] ?? '') === 'suscripcion' && in_array($c['estado'] ?? '', self::VIVAS, true)) return $c;
        }
        return null;
    }

    /**
     * Lo que se puede pagar: por cada plan que se vende, sus tres períodos con el valor sin IVA.
     * $planes = Modulos::planes(); $periodos = Planes::periodos(); $cotizar = fn(plan, período) => Planes::cotizar(...) de ESTE cliente;
     * $texto = fn(período) => «Paga 5, recibe 6»; $soloActual = tiene precio acordado con Tukán (solo se ofrece su plan).
     */
    public static function opciones(array $planes, array $periodos, callable $cotizar, callable $texto, string $planActual, bool $soloActual, int $modulosTotal = 0): array
    {
        $r = [];
        foreach ($planes as $codigo => $pl) {
            $codigo = (string) $codigo;
            if ($soloActual && $codigo !== $planActual) continue;
            $precios = [];
            foreach (array_keys($periodos) as $per) {
                if (!isset(self::CADA[$per])) continue;
                $c = $cotizar($codigo, (string) $per);
                $monto = round((float) $c['monto'], 2);
                if ($monto < 0.5) continue;   // el Panel no cobra menos de $0,50 (planes de obsequio: se arreglan con Tukán)
                $precios[$per] = ['monto' => $monto, 'meses' => (int) $c['meses_recibidos'], 'paga' => (int) $c['meses_pagados'], 'texto' => (string) $texto((string) $per)];
            }
            if (!$precios) continue;
            $elegir = (int) ($pl['modulos_elegir'] ?? 0);
            $n = count($pl['modulos'] ?? []);
            $incluye = $n > 0 ? ($modulosTotal > 0 && $n >= $modulosTotal ? 'Todos los módulos' : $n . ' módulos incluidos')
                : ($elegir > 0 ? 'La base + ' . $elegir . ' módulos a tu elección' : 'La base');
            $r[$codigo] = ['codigo' => $codigo, 'nombre' => (string) ($pl['nombre'] ?? $codigo), 'incluye' => $incluye,
                           'descripcion' => trim((string) ($pl['descripcion'] ?? '')), 'actual' => $codigo === $planActual, 'precios' => $precios];
        }
        return $r;
    }

    /**
     * Pide el cobro al Panel. $d: cliente_id, nombre (del negocio), plan, plan_nombre, periodo, meses, subtotal (sin IVA),
     * suscripcion (bool), comprador [nombre, correo, telefono], ruc, volver (dirección completa de Mi plan).
     * Devuelve el cobro (con url_pago) o lanza el mensaje para mostrar.
     */
    public static function crear(array $d, string $email = ''): array
    {
        $auto = !empty($d['suscripcion']);
        $periodo = (string) $d['periodo'];
        $negocio = (string) $d['nombre'];
        $desc = 'Plan ' . $d['plan_nombre'] . ' · ' . ($auto ? 'suscripción ' . (self::CADA[$periodo] ?? $periodo) : (int) $d['meses'] . ((int) $d['meses'] === 1 ? ' mes' : ' meses')) . ' · ' . $negocio;
        $ruc = preg_replace('/\D/', '', (string) ($d['ruc'] ?? ''));
        $comprador = is_array($d['comprador'] ?? null) ? $d['comprador'] : [];
        [$http, $j] = TukanCancelarPlan::llamarPanel([
            'accion' => 'crear', 'tipo' => $auto ? 'suscripcion' : 'cobro', 'cliente_id' => (string) $d['cliente_id'], 'nombre' => $negocio,
            'plan' => (string) $d['plan'], 'plan_nombre' => (string) $d['plan_nombre'], 'periodo' => $periodo, 'meses' => (int) $d['meses'],
            'subtotal' => round((float) $d['subtotal'], 2), 'descripcion' => mb_substr($desc, 0, 90),
            'comprador' => ['nombre' => (string) ($comprador['nombre'] ?? ''), 'correo' => (string) ($comprador['correo'] ?? ''), 'telefono' => (string) ($comprador['telefono'] ?? '')],
            'fiscal' => in_array(strlen((string) $ruc), [10, 13], true) ? ['identificacion' => $ruc, 'razon_social' => $negocio, 'correo' => (string) ($comprador['correo'] ?? '')] : [],
            'volver' => (string) $d['volver'],
        ], $email);
        unset(self::$cache[(string) $d['cliente_id']]);
        if ($http === 0 || $j === null) throw new RuntimeException('No se pudo conectar con el sistema de pagos. Intenta en unos minutos.');
        if (empty($j['ok'])) throw new RuntimeException((string) ($j['error'] ?? 'No se pudo crear el pago.'));
        $c = is_array($j['cobro'] ?? null) ? $j['cobro'] : [];
        if (!is_string($c['url_pago'] ?? null) || !preg_match('#^https?://#i', $c['url_pago'])) throw new RuntimeException('El sistema de pagos no devolvió el enlace. Intenta otra vez.');
        return $c;
    }

    /**
     * «Elegir otro plan»: descarta una suscripción que todavía NO se ha pagado (esperando el pago o tarjeta rechazada), para poder
     * elegir otro plan o período. Una suscripción que ya cobra no se toca aquí: para eso está «Cancelar mi plan».
     */
    public static function descartar(?array $estado, string $clienteId, int $id, string $email = ''): void
    {
        $s = null;
        foreach (is_array($estado['cobros'] ?? null) ? $estado['cobros'] : [] as $c) if (is_array($c) && (int) ($c['id'] ?? 0) === $id) $s = $c;
        if (!$s || ($s['tipo'] ?? '') !== 'suscripcion' || !in_array($s['estado'] ?? '', ['pendiente', 'rechazado'], true) || !empty($s['pagado_hasta'])) {
            throw new RuntimeException('Ese pago ya no está pendiente.');
        }
        [$http, $j] = TukanCancelarPlan::llamarPanel(['accion' => 'cancelar', 'cliente_id' => $clienteId, 'id' => $id], $email);
        unset(self::$cache[$clienteId]);
        if ($http === 0 || $j === null) throw new RuntimeException('No se pudo conectar con el sistema de pagos. Intenta en unos minutos.');
        if (empty($j['ok'])) throw new RuntimeException((string) ($j['error'] ?? 'No se pudo quitar ese pago pendiente.'));
    }

    // ------------------------------------------------------------------
    // La suscripción se ajusta sola cuando cambian los módulos o el plan DENTRO del programa (decisión de Dimitry, 10 nov 2026):
    // si el valor sube, el Panel cambia los próximos cobros y cobra aparte lo proporcional de lo que falta del período;
    // no puede bajar: los módulos ya contratados no se quitan (ni se baja de plan) mientras dure el período pagado.
    // ------------------------------------------------------------------

    /** La suscripción con tarjeta viva Y pagada (activa o en gracia, con el período pagado todavía vigente), o null. */
    public static function pagada(?array $estado, ?string $hoy = null): ?array
    {
        $s = self::suscripcion($estado);
        if (!$s || !in_array($s['estado'] ?? '', ['activa', 'en_gracia'], true)) return null;
        $hasta = substr((string) ($s['pagado_hasta'] ?? ''), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) && $hasta >= ($hoy ?? date('Y-m-d')) ? $s : null;
    }

    /** «…no se pueden quitar mientras dure el período pagado (hasta el dd/mm/aaaa)». $que = 'modulos' o 'plan'. */
    public static function textoNoBaja(array $sus, string $que = 'modulos'): string
    {
        $f = self::fecha((string) ($sus['pagado_hasta'] ?? ''));
        return ($que === 'plan' ? 'No se puede bajar de plan' : 'Los módulos ya contratados no se pueden quitar') . ' mientras dure el período pagado' . ($f !== '' ? ' (hasta el ' . $f . ')' : '') . '.';
    }

    /**
     * Pide al Panel «cambiar»: el valor nuevo (sin IVA) de la suscripción viva del cliente, del mismo plan y período.
     * $pasaACompleto: se pasa al plan Completo (decisión de Dimitry: eso SÍ se acepta aunque el valor baje; rige desde el próximo cobro).
     * Devuelve ['resultado' => 'subio' | 'baja_al_renovar' | 'igual' | 'no_baja' | 'sin_pagar' | 'sin_panel' | 'error', 'tipo' => 'ok' | 'error', 'texto' => para mostrar].
     */
    public static function ajustar(string $clienteId, float $subtotal, string $email = '', bool $pasaACompleto = false): array
    {
        [$http, $j] = TukanCancelarPlan::llamarPanel(['accion' => 'cambiar', 'cliente_id' => $clienteId, 'subtotal' => round($subtotal, 2)] + ($pasaACompleto ? ['pasa_a_completo' => true] : []), $email);
        unset(self::$cache[$clienteId]);
        if ($http === 0 || $j === null) {
            return ['resultado' => 'sin_panel', 'tipo' => 'error', 'texto' => 'No se pudo avisar al Panel Tukán Central: la suscripción con tarjeta se ajustará cuando el Panel responda (revísala en el Panel).'];
        }
        if (!empty($j['ok'])) {
            if (empty($j['cambio'])) return ['resultado' => 'igual', 'tipo' => 'ok', 'texto' => ''];
            $c = is_array($j['cobro'] ?? null) ? $j['cobro'] : [];
            if (!empty($j['baja_al_renovar'])) {   // pasó al Completo y paga menos: desde el próximo cobro; lo ya pagado no se devuelve
                $f = self::fecha((string) ($c['pagado_hasta'] ?? ''));
                return ['resultado' => 'baja_al_renovar', 'tipo' => 'ok', 'cobro' => $c, 'texto' => 'Desde el próximo cobro' . ($f !== '' ? ' (' . $f . ')' : '') . ' la suscripción con tarjeta queda en '
                    . self::dinero((float) ($c['subtotal'] ?? $subtotal)) . ' + IVA (' . self::dinero((float) ($c['total'] ?? 0)) . ' con IVA). Lo ya pagado no se devuelve.'];
            }
            $p = is_array($j['proporcional'] ?? null) ? $j['proporcional'] : null;
            $t = 'La suscripción con tarjeta queda en ' . self::dinero((float) ($c['subtotal'] ?? $subtotal)) . ' + IVA (' . self::dinero((float) ($c['total'] ?? 0)) . ' con IVA) desde el próximo cobro.';
            if ($p) $t .= ' Por los días que faltan del período pagado se cobra aparte ' . self::dinero((float) ($p['total'] ?? 0)) . ' con IVA'
                . (!empty($p['url_pago']) ? ': ' . $p['url_pago'] : '.') . ' El dueño también lo ve en su Mi plan.';
            return ['resultado' => 'subio', 'tipo' => 'ok', 'texto' => $t, 'proporcional' => $p, 'cobro' => $c];
        }
        $cod = (string) ($j['codigo'] ?? '');
        if ($http === 404) return ['resultado' => 'igual', 'tipo' => 'ok', 'texto' => ''];   // ya no tiene suscripción viva: nada que ajustar
        return ['resultado' => in_array($cod, ['no_baja', 'sin_pagar'], true) ? $cod : 'error', 'tipo' => 'error',
                'texto' => 'Suscripción con tarjeta: ' . (string) ($j['error'] ?? 'el Panel no aceptó el cambio de valor.')];
    }

    /**
     * Hace un cambio de módulos, de precio o de plan DENTRO del programa cuidando la suscripción con tarjeta del cliente.
     *   $cambio = fn(): mixed   hace el cambio (corre dentro de una transacción: si no se permite, se deshace)
     *   $valor  = fn(string $periodo): float   el valor sin IVA de su plan en ese período, como quedó después del cambio
     *   $quita  = fn(mixed $resultadoDelCambio): bool   ¿el cambio quitó módulos ya contratados?
     *   $pagoConTarjeta = su último pago fue con Plux (para avisar si el Panel no contesta y no se sabe si tiene suscripción)
     * Con suscripción viva y pagada: si quita módulos o el valor baja, lanza el mensaje y NO cambia nada; si sube, lo manda al Panel.
     * $pasaACompleto: el cambio es pasar al plan Completo; se permite aunque el valor baje (el Completo trae todo: no se quita nada).
     * Devuelve ['resultado' => lo que devolvió $cambio, 'aviso' => null | ['texto' => …, 'tipo' => 'ok' | 'error'], 'ajuste' => null | lo de ajustar()].
     */
    public static function cambiarCuidando(PDO $db, string $clienteId, string $email, callable $cambio, callable $valor, callable $quita,
                                           bool $pagoConTarjeta = false, string $que = 'modulos', bool $pasaACompleto = false): array
    {
        $estado = self::estado($clienteId, $email, true);
        $sus = self::pagada($estado);
        $antesPeriodo = $sus ? (string) ($sus['periodo'] ?? 'mensual') : 'mensual';
        $antes = $estado === null && $pagoConTarjeta ? (float) $valor($antesPeriodo) : null;
        $propia = !$db->inTransaction();
        if ($propia) $db->beginTransaction();
        try {
            $r = $cambio();
            if ($sus) {
                $nuevo = round((float) $valor($antesPeriodo), 2);
                if (!$pasaACompleto && ($quita($r) || $nuevo < round((float) $sus['subtotal'], 2) - 0.005)) throw new RuntimeException(self::textoNoBaja($sus, $que));
            }
            if ($propia) $db->commit();
        } catch (\Throwable $e) {
            if ($propia && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if ($sus) {
            if (abs($nuevo - round((float) $sus['subtotal'], 2)) <= 0.005) return ['resultado' => $r, 'aviso' => null, 'ajuste' => null];   // no mueve el valor
            $a = self::ajustar($clienteId, $nuevo, $email, $pasaACompleto);
            return ['resultado' => $r, 'aviso' => $a['texto'] !== '' ? ['texto' => $a['texto'], 'tipo' => $a['tipo']] : null, 'ajuste' => $a];
        }
        if ($antes !== null && abs((float) $valor($antesPeriodo) - $antes) >= 0.005) {   // el Panel no contesta y este cliente paga con tarjeta
            return ['resultado' => $r, 'ajuste' => null, 'aviso' => ['tipo' => 'error', 'texto' => 'No se pudo consultar al Panel Tukán Central: si este cliente tiene suscripción con tarjeta, se ajustará cuando el Panel responda (revísala en el Panel).']];
        }
        return ['resultado' => $r, 'aviso' => null, 'ajuste' => null];
    }

    // ------------------------------------------------------------------
    // Empujar al plan Completo (decisión de Dimitry, 10 nov 2026): «el valor es más caro que el Completo, le sugerimos coger el Completo».
    // ------------------------------------------------------------------

    /** Código del plan Completo: el más caro de los que traen todos los módulos (o null si no hay). $planes = Modulos::planes(). */
    public static function completo(array $planes, int $modulosTotal): ?string
    {
        $r = null;
        foreach ($planes as $codigo => $pl) if ($modulosTotal > 0 && count($pl['modulos'] ?? []) >= $modulosTotal) $r = (string) $codigo;   // vienen del más barato al más caro
        return $r;
    }

    /**
     * ¿Conviene sugerirle el Completo? $paga = lo que paga (o pagaría) al mes con su plan y sus módulos; $completo = lo que le costaría el
     * Completo al mes. Devuelve null (ya tiene el Completo, tiene precio acordado o está lejos) o
     * ['nivel' => 'mayor' (paga igual o más) | 'cerca' (a $5 o menos), 'paga' => X, 'completo' => Y, 'falta' => Y − X, 'plan' => código, 'nombre' => nombre].
     */
    public static function sugerirCompleto(array $planes, int $modulosTotal, string $planActual, float $paga, float $completo, bool $precioAcordado = false): ?array
    {
        $cod = self::completo($planes, $modulosTotal);
        if ($cod === null || $cod === $planActual || $precioAcordado || $completo <= 0) return null;
        $falta = round($completo - $paga, 2);
        if ($falta > 5.0) return null;
        return ['nivel' => $falta <= 0.005 ? 'mayor' : 'cerca', 'paga' => round($paga, 2), 'completo' => round($completo, 2), 'falta' => max(0.0, $falta),
                'plan' => $cod, 'nombre' => (string) ($planes[$cod]['nombre'] ?? 'Completo')];
    }

    /**
     * El aviso (color de advertencia) con el botón «Pasarme al Completo» (manda accion=pasar_completo). $csrf = '' → sin botón (para Tukán).
     * $siAgrega = nombre del módulo que está por pedir («Si agregas X…»); $otro = HTML de otro botón al lado (p. ej. «Pedir solo este módulo»).
     */
    public static function htmlSugerencia(?array $s, string $csrf = '', string $accion = '', string $siAgrega = '', string $otro = '', bool $deTukan = false): string
    {
        if (!$s) return '';
        $n = self::e($s['nombre']);
        $todo = 'trae <strong>TODOS los módulos y el Asistente IA</strong>';
        if ($deTukan) {
            $t = $s['nivel'] === 'mayor' ? 'Con sus módulos paga <strong>' . self::dinero($s['paga']) . '</strong> al mes. El plan ' . $n . ' cuesta <strong>' . self::dinero($s['completo']) . '</strong> y ' . $todo . '. Conviene sugerirle el ' . $n . '.'
                : 'Paga <strong>' . self::dinero($s['paga']) . '</strong> al mes: por <strong>' . self::dinero($s['falta']) . '</strong> más tendría el ' . $n . ' con todo. Conviene sugerírselo.';
        } elseif ($s['nivel'] === 'mayor') {
            $t = ($siAgrega !== '' ? 'Si agregas «' . self::e($siAgrega) . '» pagarías <strong>' : 'Con tus módulos pagas <strong>') . self::dinero($s['paga']) . '</strong> al mes. El plan ' . $n . ' cuesta <strong>'
                . self::dinero($s['completo']) . '</strong> y ' . $todo . '. Te sugerimos pasarte al ' . $n . '.';
        } else {
            $t = ($siAgrega !== '' ? 'Si agregas «' . self::e($siAgrega) . '» pagarías <strong>' . self::dinero($s['paga']) . '</strong> al mes. ' : '')
                . 'Por <strong>' . self::dinero($s['falta']) . '</strong> más tienes el ' . $n . ' con todo: ' . $todo . '.';
        }
        $h = '<div class="aviso tukan-sugerencia" role="status" data-sugerencia="' . self::e($s['nivel']) . '" style="background:#fff8e1;border:1px solid #f2c94c;color:#5c4400"><p style="margin:0 0 8px">' . $t . '</p>';
        if ($csrf !== '') {
            $h .= '<div class="acciones"><form method="post" action="' . self::e($accion) . '" style="display:inline">' . $csrf . '<input type="hidden" name="accion" value="pasar_completo">'
                . '<button class="boton boton-chico" type="submit">Pasarme al ' . $n . '</button></form>' . $otro . '</div>';
        }
        return $h . '</div>';
    }

    // ------------------------------------------------------------------
    // Subir de plan SIN suscripción con tarjeta (decisión de Dimitry, 10 nov 2026): el cliente que pagó su período (por transferencia,
    // etc.) y quiere el plan mayor paga el PRORRATEO de los días que faltan: con tarjeta (pedido «mejora» al Panel) o por
    // transferencia («Enviar por WhatsApp» con el cálculo; el plan lo cambia Tukán al registrar el pago).
    // ------------------------------------------------------------------

    /**
     * El prorrateo por subir de plan: (valor mensual nuevo − valor mensual de hoy) × días que faltan ÷ 30, a 2 decimales.
     * $hasta = hasta cuándo está pagado (AAAA-MM-DD). Devuelve null si no hay período pagado vigente (demo o vencido: paga el plan completo)
     * o ['dias' => N, 'hasta' => AAAA-MM-DD, 'subtotal' => sin IVA, 'iva' => %, 'total' => con IVA].
     */
    public static function prorrateo(float $nuevoMensual, float $hoyMensual, ?string $hasta, ?string $hoy = null, float $iva = 15.0): ?array
    {
        $hasta = substr((string) $hasta, 0, 10);
        $hoy = $hoy ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) || $hasta < $hoy) return null;
        $dias = (int) round((strtotime($hasta) - strtotime($hoy)) / 86400);
        $sub = max(0.0, round(($nuevoMensual - $hoyMensual) * $dias / 30, 2));
        return ['dias' => $dias, 'hasta' => $hasta, 'subtotal' => $sub, 'iva' => $iva, 'total' => round($sub * (1 + $iva / 100), 2)];
    }

    /** El mensaje listo para el WhatsApp de Tukán (pagar el cambio por transferencia). */
    public static function textoWhatsappMejora(array $p, string $negocio, string $programa, string $plan): string
    {
        return 'Hola, soy ' . $negocio . ' (' . $programa . '). Quiero pasar al plan ' . $plan . ' pagando por transferencia. Me faltan ' . $p['dias'] . ($p['dias'] === 1 ? ' día' : ' días')
            . ' (hasta el ' . self::fecha($p['hasta']) . '). Valor del cambio: ' . self::dinero((float) $p['total']) . ' con IVA.';
    }

    /**
     * El cálculo a la vista con sus dos botones: «Pagar $Y con tarjeta» (solo si el Panel tiene el pago encendido; manda accion=pagar_mejora)
     * y «Enviar por WhatsApp» ($waUrl = enlace de WhatsApp de Tukán con el texto listo; '' = sin botón, p. ej. en la app de Google Play).
     * Si la diferencia es menor a $1 (o $0: ya paga igual o más que el plan nuevo) solo sale «Pasarme ahora (no se cobra)» (accion=pasar_sin_cobro).
     */
    public static function htmlProrrateo(array $p, string $plan, string $csrf, string $accion, bool $conTarjeta, string $waUrl): string
    {
        $h = '<div class="aviso tukan-sugerencia" role="status" data-completo="prorrateo" style="background:#fff8e1;border:1px solid #f2c94c;color:#5c4400"><p style="margin:0 0 8px"><strong>Pasar al '
            . self::e($plan) . ':</strong> te faltan <span data-dias>' . (int) $p['dias'] . '</span> ' . ($p['dias'] === 1 ? 'día' : 'días') . ' (hasta el ' . self::e(self::fecha($p['hasta'])) . '). Diferencia: <span data-subtotal>'
            . self::dinero((float) $p['subtotal']) . '</span> + IVA = <strong data-total>' . self::dinero((float) $p['total']) . '</strong>.</p>'
            . '<p style="margin:0 0 8px">Tu fecha de vencimiento no cambia. Desde tu próxima renovación pagas el plan ' . self::e($plan) . '.</p><div class="acciones">';
        if ((float) $p['subtotal'] < 1) {   // menos de $1 (o nada): no se cobra y pasa directo, haya o no pago con tarjeta
            return $h . '<form method="post" action="' . self::e($accion) . '" style="display:inline">' . $csrf . '<input type="hidden" name="accion" value="pasar_sin_cobro">'
                . '<button class="boton boton-chico" type="submit">Pasarme ahora (no se cobra)</button></form></div><p class="suave" data-sin-cobro style="margin:8px 0 0">La diferencia es menor a $1: no se cobra.</p></div>';
        }
        if ($conTarjeta) {
            $h .= '<form method="post" action="' . self::e($accion) . '" style="display:inline">' . $csrf . '<input type="hidden" name="accion" value="pagar_mejora">'
                . '<button class="boton boton-chico" type="submit">Pagar ' . self::dinero((float) $p['total']) . ' con tarjeta</button></form>';
        }
        if ($waUrl !== '') $h .= '<a class="boton boton-wa boton-chico" href="' . self::e($waUrl) . '" target="_blank" rel="noopener">Enviar por WhatsApp</a>';
        return $h . '</div>' . ($waUrl !== '' ? '<p class="suave" style="margin:8px 0 0">Si pagas por transferencia, toca «Enviar por WhatsApp»: Tukán te cambia el plan cuando registra tu pago.</p>' : '') . '</div>';
    }

    /**
     * Pide al Panel el cobro del prorrateo («mejora»). $d: cliente_id, nombre, plan, plan_nombre, subtotal (sin IVA), hasta, comprador, ruc, volver.
     * Devuelve ['resultado' => 'pagar' (con 'url') | 'minimo' (menos de $1: no se cobra) | 'ya_tiene' | 'sin_panel' | 'error', 'texto' => para mostrar].
     */
    public static function mejora(array $d, string $email = ''): array
    {
        $ruc = preg_replace('/\D/', '', (string) ($d['ruc'] ?? ''));
        $comprador = is_array($d['comprador'] ?? null) ? $d['comprador'] : [];
        [$http, $j] = TukanCancelarPlan::llamarPanel([
            'accion' => 'mejora', 'cliente_id' => (string) $d['cliente_id'], 'nombre' => (string) $d['nombre'], 'plan' => (string) $d['plan'], 'plan_nombre' => (string) $d['plan_nombre'],
            'subtotal' => round((float) $d['subtotal'], 2), 'hasta' => (string) $d['hasta'],
            'comprador' => ['nombre' => (string) ($comprador['nombre'] ?? ''), 'correo' => (string) ($comprador['correo'] ?? ''), 'telefono' => (string) ($comprador['telefono'] ?? '')],
            'fiscal' => in_array(strlen((string) $ruc), [10, 13], true) ? ['identificacion' => $ruc, 'razon_social' => (string) $d['nombre'], 'correo' => (string) ($comprador['correo'] ?? '')] : [],
            'volver' => (string) $d['volver'],
        ], $email);
        unset(self::$cache[(string) $d['cliente_id']]);
        if ($http === 0 || $j === null) return ['resultado' => 'sin_panel', 'texto' => 'No se pudo conectar con el sistema de pagos. Intenta en unos minutos o usa «Enviar por WhatsApp».'];
        $c = is_array($j['cobro'] ?? null) ? $j['cobro'] : [];
        if (!empty($j['ok']) && is_string($c['url_pago'] ?? null) && preg_match('#^https?://#i', $c['url_pago'])) return ['resultado' => 'pagar', 'url' => $c['url_pago'], 'texto' => '', 'cobro' => $c];
        $cod = (string) ($j['codigo'] ?? '');
        if ($cod === 'minimo') return ['resultado' => 'minimo', 'texto' => 'La diferencia es menor a $1: no se cobra.'];
        if ($cod === 'ya_tiene') return ['resultado' => 'ya_tiene', 'texto' => 'Ya tienes una suscripción con tarjeta: toca otra vez «Pasarme» y el cambio se ajusta en ella.'];
        return ['resultado' => 'error', 'texto' => (string) ($j['error'] ?? 'No se pudo crear el pago del cambio de plan.')];
    }

    /**
     * El cobro de «mejora» ya PAGADO que todavía no se aplicó aquí (o null): el plan se cambia cuando Mi plan lo ve en «estado»
     * (origen «mejora», estado «pagado», con su plan). Se aplica una sola vez: marcarMejora() guarda el id del cobro (tabla tukan_mejoras, se crea sola).
     */
    public static function mejoraPagada(PDO $db, string $clienteId, ?array $estado): ?array
    {
        $pagadas = array_filter(is_array($estado['cobros'] ?? null) ? $estado['cobros'] : [],
            fn($c) => is_array($c) && ($c['origen'] ?? '') === 'mejora' && ($c['estado'] ?? '') === 'pagado' && is_string($c['plan'] ?? null) && $c['plan'] !== '');
        if (!$pagadas) return null;
        self::asegurarMejoras($db);
        $st = $db->prepare("SELECT cobro_id FROM tukan_mejoras WHERE cliente_id = ? AND aplicada <> ''");
        $st->execute([$clienteId]);
        $ya = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        usort($pagadas, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        foreach ($pagadas as $c) if (!in_array((int) $c['id'], $ya, true)) return $c;
        return null;
    }

    /** La tabla de las mejoras (se crea sola): una fila por cobro; «aplicada» vacía = todavía esperando el pago. */
    private static function asegurarMejoras(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS tukan_mejoras (cliente_id VARCHAR(80) NOT NULL, cobro_id INTEGER NOT NULL, plan VARCHAR(60) NOT NULL, pedida VARCHAR(40) NOT NULL DEFAULT '',
                   aplicada VARCHAR(40) NOT NULL DEFAULT '', PRIMARY KEY (cliente_id, cobro_id))");
    }

    /** Anota que el cliente fue a pagar esa mejora (para que la tarea de cada hora la aplique si paga y no vuelve a abrir Mi plan). */
    public static function mejoraPendiente(PDO $db, string $clienteId, int $cobroId, string $plan): void
    {
        self::asegurarMejoras($db);
        $db->prepare("INSERT INTO tukan_mejoras (cliente_id, cobro_id, plan, pedida, aplicada) VALUES (?, ?, ?, ?, '') ON CONFLICT (cliente_id, cobro_id) DO NOTHING")->execute([$clienteId, $cobroId, $plan, date('c')]);
    }

    /** Anota que ese cobro de mejora ya se aplicó (para no aplicarlo dos veces). */
    public static function marcarMejora(PDO $db, string $clienteId, int $cobroId, string $plan): void
    {
        self::asegurarMejoras($db);
        $db->prepare("INSERT INTO tukan_mejoras (cliente_id, cobro_id, plan, pedida, aplicada) VALUES (?, ?, ?, ?, ?)
                      ON CONFLICT (cliente_id, cobro_id) DO UPDATE SET aplicada = EXCLUDED.aplicada WHERE tukan_mejoras.aplicada = ''")->execute([$clienteId, $cobroId, $plan, date('c'), date('c')]);
    }

    /**
     * Para la tarea de cada hora: de los clientes que fueron a pagar una mejora en los últimos $dias días y todavía no se aplicó, pregunta
     * al Panel y, si ya está pagada, la aplica con $aplicar = fn(string $clienteId, array $cobro): bool (true = aplicada o ya no hace falta).
     * Devuelve cuántas aplicó. Si el Panel no contesta, no pasa nada: se intenta en la hora siguiente.
     */
    public static function aplicarMejorasPendientes(PDO $db, callable $aplicar, int $dias = 15): int
    {
        self::asegurarMejoras($db);
        $st = $db->prepare("SELECT DISTINCT cliente_id FROM tukan_mejoras WHERE aplicada = '' AND pedida >= ?");
        $st->execute([date('c', time() - $dias * 86400)]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (string) $cid;
            for ($i = 0; $i < 5 && ($c = self::mejoraPagada($db, $cid, self::estado($cid, '', true))) !== null; $i++) {
                if (!$aplicar($cid, $c)) break;
                self::marcarMejora($db, $cid, (int) $c['id'], (string) $c['plan']);
                $n++;
            }
        }
        return $n;
    }

    private static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function dinero(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    private static function fecha(?string $f): string
    {
        $t = $f ? strtotime(substr($f, 0, 10)) : false;
        return $t ? date('d/m/Y', $t) : '';
    }

    /**
     * La sección «Paga tu plan con tarjeta» de Mi plan. '' si el Panel no contesta o el pago con tarjeta está apagado
     * (Mi plan queda como siempre). $vuelta = lo que trae ?plux= al volver de la página de pago; $demo = cuenta en demo.
     * El formulario manda accion=pagar_tarjeta con opcion="plan|período" y forma=suscripcion|una_vez;
     * «Elegir otro plan» manda accion=descartar_pago_tarjeta con id. $sugerencia = el aviso «te sugerimos el Completo» (htmlSugerencia);
     * $elegir = plan que sale marcado (viene de «Pasarme al Completo»).
     */
    public static function html(?array $estado, array $opciones, string $csrf, string $accion, bool $demo = false, string $vuelta = '', string $sugerencia = '', string $elegir = ''): string
    {
        if (!self::activo($estado)) return '';
        $iva = rtrim(rtrim(number_format((float) ($estado['iva'] ?? 15), 2, ',', ''), '0'), ',');
        $h = '<section class="bloque tukan-pago" id="pago-tarjeta" aria-labelledby="tp-titulo"><h2 id="tp-titulo">Paga tu plan con tarjeta</h2>';
        if ($vuelta === 'aprobado') {
            $h .= '<p class="aviso aviso-ok" data-pago="aprobado">¡Pago recibido! Tu plan se activa en unos segundos (vuelve a abrir esta página). La factura te llega al correo.</p>';
        } elseif ($vuelta !== '') {
            $h .= '<p class="aviso aviso-error" data-pago="no-completado">El pago no se completó y no se te cobró nada. Puedes intentarlo otra vez, con la misma tarjeta o con otra.</p>';
        }
        if (!empty($estado['pruebas'])) $h .= '<p class="aviso" data-pago="pruebas"><strong>Modo de prueba:</strong> no se cobra de verdad.</p>';

        $cobros = array_values(array_filter(is_array($estado['cobros'] ?? null) ? $estado['cobros'] : [],
            fn($c) => is_array($c) && in_array($c['estado'] ?? '', ['pendiente', 'rechazado', 'activa', 'en_gracia', 'vencida'], true)));
        if ($cobros) {
            $h .= '<ul class="lista">';
            foreach ($cobros as $c) {
                $sus = ($c['tipo'] ?? '') === 'suscripcion';
                $detalle = self::e($c['estado_texto'] ?? $c['estado']) . ' · ' . self::dinero((float) ($c['total'] ?? 0)) . ' con IVA';
                if ($sus && !empty($c['cobra_sola'])) $detalle .= ' · se cobra sola a tu tarjeta';
                if ($sus && !empty($c['pagado_hasta'])) $detalle .= ' · pagada hasta el ' . self::e(self::fecha((string) $c['pagado_hasta']));
                $h .= '<li data-cobro="' . (int) ($c['id'] ?? 0) . '" data-estado="' . self::e($c['estado']) . '"><span><strong>' . self::e($c['descripcion'] ?? 'Tu plan') . '</strong><br><small>' . $detalle . '</small></span><span>';
                if (!empty($c['url_pago']) && preg_match('#^https?://#i', (string) $c['url_pago'])) $h .= '<a class="boton boton-chico" href="' . self::e($c['url_pago']) . '">Pagar ahora</a> ';
                if ($sus && in_array($c['estado'], ['pendiente', 'rechazado'], true) && empty($c['pagado_hasta'])) {
                    $h .= '<form method="post" action="' . self::e($accion) . '" style="display:inline">' . $csrf . '<input type="hidden" name="accion" value="descartar_pago_tarjeta">'
                        . '<input type="hidden" name="id" value="' . (int) ($c['id'] ?? 0) . '"><button class="boton boton-claro boton-chico" type="submit">Elegir otro plan</button></form>';
                }
                $h .= '</span></li>';
            }
            $h .= '</ul>';
        }
        if (self::suscripcion($estado)) {
            return $h . '<p class="suave">Ya tienes una suscripción con tarjeta: no hace falta pagar otra vez. Si quieres dejarla, usa «Cancelar mi plan» más abajo.</p></section>';
        }
        if (!$opciones) return $cobros || $vuelta !== '' || !empty($estado['pruebas']) ? $h . '</section>' : '';
        $h .= $sugerencia;
        if (!isset($opciones[$elegir])) $elegir = '';

        $h .= '<p>' . ($demo ? 'Elige tu plan y paga aquí mismo: tu cuenta queda activa sin esperar a nadie y todo lo que cargaste en la demo se queda.'
                             : 'Elige el plan y cada cuánto pagar. Se activa solo apenas se aprueba el pago.')
            . ' Los valores son <strong>sin IVA</strong>: al pagar se suma el IVA (' . self::e($iva) . ' %).</p>'
            . '<form method="post" action="' . self::e($accion) . '">' . $csrf . '<input type="hidden" name="accion" value="pagar_tarjeta"><div class="rejilla rejilla-2">';
        $primera = true;
        foreach ($opciones as $o) {
            $h .= '<div class="bloque" data-plan="' . self::e($o['codigo']) . '"><h3>' . self::e($o['nombre']) . ($o['actual'] && !$demo ? ' <small>(tu plan)</small>' : '') . '</h3>'
                . '<p class="suave">' . self::e($o['incluye']) . ($o['descripcion'] !== '' ? '. ' . self::e($o['descripcion']) : '') . '</p><div class="opciones">';
            foreach ($o['precios'] as $per => $p) {
                $marcar = $primera && ($elegir !== '' ? $o['codigo'] === $elegir : ($o['actual'] || count($opciones) === 1));
                if ($marcar) $primera = false;
                $h .= '<label class="opcion"><input type="radio" name="opcion" value="' . self::e($o['codigo'] . '|' . $per) . '" required' . ($marcar ? ' checked' : '') . '>'
                    . '<span><strong>' . self::e($p['texto']) . '</strong><small><span data-valor="' . self::e($o['codigo'] . '|' . $per) . '">' . self::dinero((float) $p['monto']) . '</span> + IVA '
                    . self::e(self::CADA[$per]) . '</small></span></label>';
            }
            $h .= '</div></div>';
        }
        $h .= '</div><div class="opciones" style="margin-top:12px">'
            . '<label class="opcion"><input type="radio" name="forma" value="suscripcion" checked><span><strong>Suscripción: se cobra sola</strong>'
            . '<small>Se cobra sola a tu tarjeta cada período (cada mes, cada 6 meses o cada año, según elijas). La cancelas cuando quieras, sin multas.</small></span></label>'
            . '<label class="opcion"><input type="radio" name="forma" value="una_vez"><span><strong>Pagar solo esta vez</strong>'
            . '<small>Pagas este período y nada más. Cuando se acabe, vuelves a pagar aquí.</small></span></label></div>'
            . '<div class="acciones" style="margin-top:12px"><button class="boton" type="submit">Pagar con tarjeta</button></div>'
            . '<p class="suave">Pagas en la página segura de Plux; aquí no se guarda tu tarjeta. La factura te llega al correo.</p></form></section>';
        return $h;
    }
}
