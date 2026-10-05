<?php
$id = (int) ($_SESSION['admin_viendo'] ?? 0);
unset($_SESSION['usuario_id'], $_SESSION['admin_viendo'], $_SESSION['admin_viendo_usuario']);
redirigir($id ? 'admin_salon' : 'admin', $id ? ['id' => $id] : []);
