<?php
require __DIR__ . '/lib.php';
if (usuario()) redirect('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $u = q('SELECT id,password,estado FROM usuarios WHERE email=?', [$email])->fetch();
    $ok = $u && password_verify($pass, $u['password']);
    if ($ok && $u['estado'] === 'ACTIVO') {
        session_regenerate_id(true);          // renueva la sesión al autenticarse
        $_SESSION['uid'] = (int)$u['id'];
        redirect('index.php');
    }
    $error = $ok ? 'Tu cuenta está inactiva.' : 'Correo o contraseña incorrectos.';
}
encabezado('Iniciar sesión');
if ($error) echo '<div class="msg err">' . h($error) . '</div>';
echo '<form method="post" style="max-width:340px">' . csrf_field()
   . campo('email', 'Correo', $_POST['email'] ?? '', 'email', 'required')
   . campo('password', 'Contraseña', '', 'password', 'required')
   . '<p><button class="btn">Ingresar</button></p></form>';
pie();
