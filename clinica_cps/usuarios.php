<?php
require __DIR__ . '/lib.php';
$a = $_GET['a'] ?? 'lista';

if ($a === 'nuevo' || $a === 'editar') {
    $id = $a === 'editar' ? (int)($_GET['id'] ?? 0) : null;
    $yo = exigir($id ? 'usuarios.editar' : 'usuarios.crear');
    $d = $id ? q('SELECT id,nombre,email,estado,rol_id FROM usuarios WHERE id=?', [$id])->fetch()
             : ['nombre' => '', 'email' => '', 'estado' => 'ACTIVO', 'rol_id' => ''];
    if (!$d) pagina_error('Usuario no encontrado.');
    $err = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $c = ['nombre' => trim((string)($_POST['nombre'] ?? '')), 'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
              'estado' => (string)($_POST['estado'] ?? 'ACTIVO'), 'rol_id' => (int)($_POST['rol_id'] ?? 0)];
        $pass = (string)($_POST['password'] ?? '');
        if ($c['nombre'] === '' || mb_strlen($c['nombre']) > 100) $err[] = 'El nombre es obligatorio (máx. 100).';
        if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Correo inválido.';
        elseif (q('SELECT 1 FROM usuarios WHERE email=? AND id<>?', [$c['email'], $id ?? 0])->fetchColumn()) $err[] = 'Ese correo ya está registrado.';
        if (!in_array($c['estado'], ['ACTIVO', 'INACTIVO'], true)) $err[] = 'Estado inválido.';
        if (!q('SELECT 1 FROM roles WHERE id=?', [$c['rol_id']])->fetchColumn()) $err[] = 'Selecciona un rol válido.';
        if (!$id && $pass === '') $err[] = 'La contraseña es obligatoria.';
        if ($pass !== '' && mb_strlen($pass) < 8) $err[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($id && $id === (int)$yo['id']) {
            if ($c['estado'] !== 'ACTIVO') $err[] = 'No puedes desactivar tu propia cuenta.';
            if ($c['rol_id'] !== (int)$d['rol_id']) $err[] = 'No puedes cambiar tu propio rol.';
        }
        $d = array_merge($d, $c);
        if (!$err) {
            if ($id) {
                $sql = 'UPDATE usuarios SET nombre=?,email=?,estado=?,rol_id=?' . ($pass !== '' ? ',password=?' : '') . ' WHERE id=?';
                $p = [$c['nombre'], $c['email'], $c['estado'], $c['rol_id']];
                if ($pass !== '') $p[] = password_hash($pass, PASSWORD_DEFAULT);
                $p[] = $id;
                q($sql, $p);
                flash('Usuario actualizado.');
            } else {
                q('INSERT INTO usuarios (nombre,email,password,estado,rol_id) VALUES (?,?,?,?,?)',
                  [$c['nombre'], $c['email'], password_hash($pass, PASSWORD_DEFAULT), $c['estado'], $c['rol_id']]);
                flash('Usuario creado.');
            }
            redirect('usuarios.php');
        }
    }
    $roles = q('SELECT id,nombre FROM roles ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
    encabezado($id ? 'Editar usuario' : 'Nuevo usuario');
    errores($err);
    echo '<form method="post" style="max-width:420px">' . csrf_field()
       . campo('nombre', 'Nombre', $d['nombre']) . campo('email', 'Correo', $d['email'], 'email')
       . campo('password', $id ? 'Contraseña (déjala vacía para no cambiarla)' : 'Contraseña', '', 'password')
       . selecc('rol_id', 'Rol', $roles, $d['rol_id']) . selecc('estado', 'Estado', ['ACTIVO' => 'ACTIVO', 'INACTIVO' => 'INACTIVO'], $d['estado'])
       . '<p><button class="btn">Guardar</button> <a href="usuarios.php">Cancelar</a></p></form>';
    pie();
    exit;
}

exigir('usuarios.ver');
$rows = q('SELECT u.id,u.nombre,u.email,u.estado,r.nombre AS rol FROM usuarios u JOIN roles r ON r.id=u.rol_id ORDER BY u.id')->fetchAll();
encabezado('Usuarios');
if (puede('usuarios.crear')) echo '<p><a class="btn" href="usuarios.php?a=nuevo">+ Nuevo usuario</a></p>';
echo '<table><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th></th></tr>';
foreach ($rows as $r)
    echo '<tr><td>' . h($r['nombre']) . '</td><td>' . h($r['email']) . '</td><td>' . h($r['rol']) . '</td><td>' . h($r['estado']) . '</td><td>'
       . (puede('usuarios.editar') ? '<a href="usuarios.php?a=editar&id=' . $r['id'] . '">Editar</a>' : '') . '</td></tr>';
echo '</table>';
pie();
