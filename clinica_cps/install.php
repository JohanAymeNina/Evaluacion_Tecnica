<?php
/* Ejecutar UNA vez: http://localhost/clinica_cps/install.php  (o: php install.php). Borra y recrea las tablas. */
require __DIR__ . '/lib.php';
require __DIR__ . '/instalar.php';
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
instalar_bd(cfg('DB_NAME', 'clinica_cps'));
