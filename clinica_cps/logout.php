<?php
require __DIR__ . '/lib.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); cerrar_sesion(); }   // invalida la sesión
redirect('login.php');
