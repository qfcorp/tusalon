<?php
$sid = (int) $u['salon_id'];
$q = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT c.*,
               (SELECT MAX(inicio) FROM citas WHERE cliente_id = c.id AND estado = 'atendida') AS ultima_visita,
               (SELECT COUNT(*) FROM citas WHERE cliente_id = c.id AND estado = 'atendida') AS visitas
          FROM clientes c
         WHERE c.salon_id = ? AND c.profesional_privado_id IS NULL";
$params = [$sid];
if ($q !== '') {
    $sql .= ' AND (c.nombre ILIKE ? OR c.telefono LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . preg_replace('/\D/', '', $q) . '%';
}
$st = db()->prepare($sql . ' ORDER BY c.nombre LIMIT 300');
$st->execute($params);
$clientes = $st->fetchAll();
vista('clientes', compact('clientes', 'q'), 'Clientes · TuSalón');
