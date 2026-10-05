<?php use TuSalon\WhatsApp; ?>
<div class="cabecera">
    <h1>Clientes</h1>
    <a class="boton" href="<?= e(url('cliente')) ?>">Nuevo cliente</a>
</div>

<form method="get" class="bloque" role="search">
    <input type="hidden" name="r" value="clientes">
    <label class="oculto" for="q">Buscar</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre o celular">
</form>

<section class="bloque">
    <?php if (!$clientes): ?>
        <p class="vacio"><?= $q !== '' ? 'Nadie coincide con esa búsqueda.' : 'Aún no tienes clientes. Se agregan solos al agendar citas, o puedes crearlos aquí.' ?></p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($clientes as $c):
                $wa = WhatsApp::enlace($c['telefono'], 'Hola ' . explode(' ', $c['nombre'])[0] . ', te saludamos de ' . $u['salon'] . '. ');
            ?>
                <li>
                    <a href="<?= e(url('cliente', ['id' => $c['id']])) ?>" style="flex:1;text-decoration:none;color:inherit">
                        <div class="principal-linea"><?= e($c['nombre']) ?></div>
                        <div class="linea-sub">
                            <?= $c['telefono'] ? e($c['telefono']) : 'Sin celular' ?>
                            · <?= (int) $c['visitas'] ?> <?= (int) $c['visitas'] === 1 ? 'visita' : 'visitas' ?>
                            <?= $c['ultima_visita'] ? '· última ' . (new DateTimeImmutable($c['ultima_visita']))->format('d/m/Y') : '' ?>
                        </div>
                    </a>
                    <?php if ($wa): ?>
                        <a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp a <?= e($c['nombre']) ?>">WhatsApp</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
