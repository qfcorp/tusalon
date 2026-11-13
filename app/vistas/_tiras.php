<?php
/**
 * Avisos de arriba (identidad gráfica Tukán, 10 nov 2026): demo, plan por vencer, administrador de Tukán viendo y términos por
 * aceptar, como franjas `.tk-tira`. Los pone el diseño general arriba de cada pantalla; el Inicio los pone él mismo debajo del
 * saludo (deja $tirasMostradas = true).
 */
$tirasMostradas = true;
$ios = en_app_ios();   // app de iPhone (regla 3.1.1 de Apple): los avisos del plan van sin botón para contratar o renovar
$esDuenoAqui = es_dueno_o_admin($u);
$diasPrueba = $esDuenoAqui ? dias_prueba($u) : null;
$estadoCuenta = $esDuenoAqui ? planes()->estadoCuenta($u) : null;
$diasPlan = ($estadoCuenta === 'activo' && !empty($u['activo_hasta']))
    ? (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($u['activo_hasta']))->days : null;
$adminViendo = !empty($_SESSION['admin_viendo']) && superadmin();
// (7 nov 2026) El dueño acepta los términos y la privacidad de Tukán (src/TukanLegal.php); se acepta desde Mi plan
require_once dirname(__DIR__, 2) . '/src/TukanLegal.php';
$pideTerminos = $esDuenoAqui && !$adminViendo && !TukanLegal::aceptado(db(), (string) $u['salon_id']);
?>
<?php if ($diasPrueba !== null && $diasPrueba >= 0): ?>
    <div class="tk-tira franja-prueba"><span>⏳ <strong>Demo: <?= $diasPrueba === 0 ? 'termina hoy' : "te quedan $diasPrueba " . ($diasPrueba === 1 ? 'día' : 'días') ?>.</strong></span>
        <?php if (!$ios): ?><a class="tk-boton chico" href="<?= e(url('completa')) ?>">Contratar mi plan</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($adminViendo): ?>
    <div class="tk-tira franja-admin"><span>🛡️ Estás viendo <strong><?= e($u['salon']) ?></strong> como administrador de Tukán.</span>
        <a class="tk-boton oscuro chico" href="<?= e(url('admin_volver')) ?>">Volver al panel</a></div>
<?php endif; ?>
<?php if ($estadoCuenta === 'por_vencer'): ?>
    <div class="tk-tira franja-prueba"><span>⚠️ Tu plan venció el <?= (new DateTimeImmutable($u['activo_hasta']))->format('d/m') ?>.<?= $ios ? '' : ' Renueva en los próximos días para no perder el acceso.' ?></span>
        <?php if (!$ios): ?><a class="tk-boton chico" href="<?= e(url('completa')) ?>">Renovar</a><?php endif; ?></div>
<?php elseif ($diasPlan !== null && $diasPlan <= 5): ?>
    <div class="tk-tira franja-prueba"><span>⏳ Tu plan vence <?= $diasPlan === 0 ? 'hoy' : "en $diasPlan " . ($diasPlan === 1 ? 'día' : 'días') ?>.</span><?php if (!$ios): ?>
        <a class="tk-boton chico" href="<?= e(url('completa')) ?>">Renovar</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($pideTerminos): ?><?= TukanLegal::aviso(campo_csrf(), url('completa'), url('privacidad')) ?><?php endif; ?>
