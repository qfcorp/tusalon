<?php
$slug = (string) ($_GET['s'] ?? '');
$st = db()->prepare('SELECT id FROM salones WHERE slug = ?');
$st->execute([$slug]);
if ($sid = $st->fetchColumn()) {
    unset($_SESSION['cliente'][(int) $sid]);
}
header('Location: /?r=reservar&s=' . rawurlencode($slug));
exit;
