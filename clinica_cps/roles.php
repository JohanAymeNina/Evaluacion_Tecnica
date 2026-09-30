<?php
require __DIR__ . '/lib.php';
$a = $_GET['a'] ?? 'lista';

if ($a === 'nuevo' || $a === 'editar') {
    $id = $a === 'editar' ? (int)($_GET['id'] ?? 0) : null;
    exigir($id ? 'roles.editar' : 'roles.crear');
    $d = $id ? q('SELECT * FROM roles WHERE id=?', [$id])->fetch() : ['nombre' => ''];
    if (!$d) pagina_error('Rol no encontrado.');
    $perms = q('SELECT id,codigo,nombre,modulo FROM permisos ORDER BY modulo,id')->fetchAll();
    $sel = $id ? q('SELECT permiso_id FROM rol_permiso WHERE rol_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN) : [];
    $err = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $validos = array_map('intval', array_column($perms, 'id'));
        $sel = array_values(array_filter(array_map('intval', (array)($_POST['permisos'] ?? [])), fn($x) => in_array($x, $validos, true)));
        $d['nombre'] = $nombre;
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 50) $err[] = 'El nombre del rol debe tener entre 3 y 50 caracteres.';
        elseif (q('SELECT 1 FROM roles WHERE nombre=? AND id<>?', [$nombre, $id ?? 0])->fetchColumn()) $err[] = 'Ya existe un rol con ese nombre.';
        if (!$err) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($id) q('UPDATE roles SET nombre=? WHERE id=?', [$nombre, $id]);
                else { q('INSERT INTO roles (nombre) VALUES (?)', [$nombre]); $id = (int)$pdo->lastInsertId(); }
                q('DELETE FROM rol_permiso WHERE rol_id=?', [$id]);
                foreach ($sel as $pid) q('INSERT INTO rol_permiso (rol_id,permiso_id) VALUES (?,?)', [$id, $pid]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            flash('Rol guardado.');
            redirect('roles.php');
        }
    }
    encabezado($id ? 'Editar rol' : 'Nuevo rol');
    errores($err);
    echo '<form method="post">' . csrf_field() . campo('nombre', 'Nombre del rol', $d['nombre'], 'text', 'style="max-width:340px"');
    $mod = '';
    foreach ($perms as $p) {
        if ($p['modulo'] !== $mod) { $mod = $p['modulo']; echo '<h3>' . h($mod) . '</h3>'; }
        echo '<label><input type="checkbox" name="permisos[]" value="' . $p['id'] . '"' . (in_array((int)$p['id'], array_map('intval', $sel), true) ? ' checked' : '') . '> '
           . h($p['nombre']) . ' <span class="tag">' . h($p['codigo']) . '</span></label>';
    }
    echo '<p><button class="btn">Guardar</button> <a href="roles.php">Cancelar</a></p></form>';
    pie();
    exit;
}

exigir('roles.ver');
$rows = q('SELECT r.id,r.nombre,(SELECT COUNT(*) FROM rol_permiso WHERE rol_id=r.id) AS np,(SELECT COUNT(*) FROM usuarios WHERE rol_id=r.id) AS nu FROM roles r ORDER BY r.id')->fetchAll();
encabezado('Roles y permisos');
if (puede('roles.crear')) echo '<p><a class="btn" href="roles.php?a=nuevo">+ Nuevo rol</a></p>';
echo '<table><tr><th>Rol</th><th>Permisos</th><th>Usuarios</th><th></th></tr>';
foreach ($rows as $r)
    echo '<tr><td>' . h($r['nombre']) . '</td><td>' . $r['np'] . '</td><td>' . $r['nu'] . '</td><td>'
       . (puede('roles.editar') ? '<a href="roles.php?a=editar&id=' . $r['id'] . '">Editar permisos</a>' : '') . '</td></tr>';
echo '</table>';
pie();
