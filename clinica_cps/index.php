<?php
require __DIR__ . '/lib.php';
$u = requiere_login();
encabezado('Inicio');
echo '<p>Bienvenido, <b>' . h($u['nombre']) . '</b>. Tu rol: ' . h($u['rol']) . '.</p><p>Usa el menú superior: solo verás lo que tu rol permite.</p>';
pie();
