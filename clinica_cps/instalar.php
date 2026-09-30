<?php
/* Crea la BD, las tablas (schema.sql) y carga datos ficticios de demostración */
function instalar_bd(string $dbname, bool $silencioso = false): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbname)) throw new Exception('Nombre de BD inválido');
    $pdo = new PDO('mysql:host=' . cfg('DB_HOST', '127.0.0.1') . ';charset=utf8mb4', cfg('DB_USER', 'root'), cfg('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");
    foreach (explode(';', file_get_contents(__DIR__ . '/schema.sql')) as $sql)
        if (trim($sql) !== '') $pdo->exec($sql);

    /* Roles */
    foreach (['Administrador', 'Admisión', 'Vendedor'] as $r)
        $pdo->prepare('INSERT INTO roles (nombre) VALUES (?)')->execute([$r]);
    $rol = $pdo->query('SELECT nombre,id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);

    /* Catálogo de permisos: codigo, nombre, módulo */
    $cat = [
        ['usuarios.ver', 'Ver usuarios', 'Usuarios'], ['usuarios.crear', 'Crear usuarios', 'Usuarios'], ['usuarios.editar', 'Editar usuarios', 'Usuarios'],
        ['roles.ver', 'Ver roles', 'Roles'], ['roles.crear', 'Crear roles', 'Roles'], ['roles.editar', 'Editar roles y asignar permisos', 'Roles'],
        ['pacientes.ver', 'Consultar pacientes', 'Pacientes'], ['pacientes.crear', 'Afiliar pacientes', 'Pacientes'], ['pacientes.editar', 'Editar / desactivar pacientes', 'Pacientes'],
        ['productos.ver', 'Consultar productos y stock', 'Productos'], ['productos.crear', 'Crear productos', 'Productos'], ['productos.editar', 'Editar / desactivar productos', 'Productos'],
        ['ventas.crear', 'Registrar ventas', 'Ventas'], ['ventas.ver_propias', 'Ver ventas propias', 'Ventas'], ['ventas.ver_todas', 'Ver todas las ventas', 'Ventas'],
    ];
    $st = $pdo->prepare('INSERT INTO permisos (codigo,nombre,modulo) VALUES (?,?,?)');
    foreach ($cat as $c) $st->execute($c);
    $perm = $pdo->query('SELECT codigo,id FROM permisos')->fetchAll(PDO::FETCH_KEY_PAIR);

    /* Matriz de acceso inicial */
    $matriz = [
        'Administrador' => array_column($cat, 0),
        'Admisión'      => ['pacientes.ver', 'pacientes.crear', 'pacientes.editar'],
        'Vendedor'      => ['pacientes.ver', 'productos.ver', 'ventas.crear', 'ventas.ver_propias'],
    ];
    $st = $pdo->prepare('INSERT INTO rol_permiso (rol_id,permiso_id) VALUES (?,?)');
    foreach ($matriz as $r => $codigos) foreach ($codigos as $c) $st->execute([$rol[$r], $perm[$c]]);

    /* Usuarios de demostración */
    $usuarios = [
        ['Ana Administradora', 'admin@clinica.test', 'Admin123*', 'Administrador'],
        ['Adrián Admisión', 'admision@clinica.test', 'Admision123*', 'Admisión'],
        ['Valeria Vendedora', 'vendedor1@clinica.test', 'Vendedor123*', 'Vendedor'],
        ['Víctor Vendedor', 'vendedor2@clinica.test', 'Vendedor123*', 'Vendedor'],
    ];
    $st = $pdo->prepare('INSERT INTO usuarios (nombre,email,password,rol_id) VALUES (?,?,?,?)');
    foreach ($usuarios as $u) $st->execute([$u[0], $u[1], password_hash($u[2], PASSWORD_DEFAULT), $rol[$u[3]]]);

    /* Pacientes ficticios (el último está INACTIVO) */
    $pacientes = [
        ['DNI', '70000001', 'Ana', 'Quispe Mamani', '1990-05-12', 'ACTIVO'],
        ['DNI', '70000002', 'Luis', 'Huamán Ccori', '1985-11-03', 'ACTIVO'],
        ['DNI', '70000003', 'María', 'Condori Puma', '2001-02-20', 'ACTIVO'],
        ['CE', '000123456', 'Carlos', 'Rojas Vega', '1978-07-30', 'ACTIVO'],
        ['DNI', '70000005', 'Rosa', 'Flores Ttito', '1995-09-15', 'INACTIVO'],
    ];
    $st = $pdo->prepare('INSERT INTO pacientes (codigo,tipo_doc,num_doc,nombres,apellidos,fecha_nacimiento,fecha_afiliacion,estado) VALUES (?,?,?,?,?,?,CURDATE(),?)');
    foreach ($pacientes as $i => $p)
        $st->execute(['AF-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT), $p[0], $p[1], $p[2], $p[3], $p[4], $p[5]]);

    /* Productos ficticios: P004 sin stock, P005 inactivo */
    $productos = [
        ['P001', 'Botella reutilizable', 12.50, 10, 'ACTIVO'],
        ['P002', 'Kit de aseo', 20.00, 5, 'ACTIVO'],
        ['P003', 'Toalla de microfibra', 15.00, 8, 'ACTIVO'],
        ['P004', 'Gorro descartable (pack)', 8.00, 0, 'ACTIVO'],
        ['P005', 'Termómetro digital', 25.00, 6, 'INACTIVO'],
    ];
    $st = $pdo->prepare('INSERT INTO productos (codigo,nombre,precio,stock,estado) VALUES (?,?,?,?,?)');
    foreach ($productos as $p) $st->execute($p);

    if (!$silencioso) {
        echo "Base de datos '$dbname' creada y cargada.\n\nUsuarios de prueba:\n";
        foreach ($usuarios as $u) echo "  {$u[3]}: {$u[1]} / {$u[2]}\n";
        echo "\nAbre http://localhost/clinica_cps/login.php\n";
    }
}
