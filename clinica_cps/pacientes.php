<?php
require __DIR__ . '/lib.php';
$a = $_GET['a'] ?? 'lista';
$POST = $_SERVER['REQUEST_METHOD'] === 'POST';

function validar_paciente(array $d, ?int $id): array {
    $e = [];
    $c = ['tipo_doc' => (string)($d['tipo_doc'] ?? ''), 'num_doc' => strtoupper(trim((string)($d['num_doc'] ?? ''))),
          'nombres' => trim((string)($d['nombres'] ?? '')), 'apellidos' => trim((string)($d['apellidos'] ?? '')),
          'fecha_nacimiento' => trim((string)($d['fecha_nacimiento'] ?? '')), 'telefono' => trim((string)($d['telefono'] ?? '')),
          'email' => trim((string)($d['email'] ?? '')), 'estado' => (string)($d['estado'] ?? 'ACTIVO')];
    if (!in_array($c['tipo_doc'], ['DNI', 'CE', 'PASAPORTE'], true)) $e[] = 'Tipo de documento inválido.';
    elseif ($c['tipo_doc'] === 'DNI' && !preg_match('/^\d{8}$/', $c['num_doc'])) $e[] = 'El DNI debe tener 8 dígitos.';
    elseif ($c['tipo_doc'] !== 'DNI' && !preg_match('/^[A-Z0-9]{6,12}$/', $c['num_doc'])) $e[] = 'Documento inválido (6 a 12 letras o números).';
    if ($c['nombres'] === '' || mb_strlen($c['nombres']) > 80) $e[] = 'Los nombres son obligatorios (máx. 80).';
    if ($c['apellidos'] === '' || mb_strlen($c['apellidos']) > 80) $e[] = 'Los apellidos son obligatorios (máx. 80).';
    $f = DateTime::createFromFormat('!Y-m-d', $c['fecha_nacimiento']);
    if (!$f || $f->format('Y-m-d') !== $c['fecha_nacimiento']) $e[] = 'Fecha de nacimiento inválida.';
    elseif ($c['fecha_nacimiento'] > date('Y-m-d')) $e[] = 'La fecha de nacimiento no puede ser futura.';
    if ($c['telefono'] !== '' && !preg_match('/^[0-9+\s-]{6,20}$/', $c['telefono'])) $e[] = 'Teléfono inválido.';
    if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $e[] = 'Correo inválido.';
    if (!in_array($c['estado'], ['ACTIVO', 'INACTIVO'], true)) $e[] = 'Estado inválido.';
    if (!$e && q('SELECT 1 FROM pacientes WHERE tipo_doc=? AND num_doc=? AND id<>?', [$c['tipo_doc'], $c['num_doc'], $id ?? 0])->fetchColumn())
        $e[] = 'Ya existe un paciente con ese tipo y número de documento.';
    return [$e, $c];
}

if ($a === 'nuevo' || $a === 'editar') {
    $id = $a === 'editar' ? (int)($_GET['id'] ?? 0) : null;
    exigir($id ? 'pacientes.editar' : 'pacientes.crear');
    $d = $id ? q('SELECT * FROM pacientes WHERE id=?', [$id])->fetch() : ['tipo_doc' => 'DNI', 'estado' => 'ACTIVO'];
    if (!$d) pagina_error('Paciente no encontrado.');
    $err = [];
    if ($POST) {
        [$err, $c] = validar_paciente($_POST, $id);
        $d = array_merge($d, $c);
        if (!$err) {
            try {
                if ($id) {
                    q('UPDATE pacientes SET tipo_doc=?,num_doc=?,nombres=?,apellidos=?,fecha_nacimiento=?,telefono=?,email=?,estado=? WHERE id=?',
                      [$c['tipo_doc'], $c['num_doc'], $c['nombres'], $c['apellidos'], $c['fecha_nacimiento'], $c['telefono'] ?: null, $c['email'] ?: null, $c['estado'], $id]);
                    flash('Paciente actualizado.');
                } else {
                    q('INSERT INTO pacientes (codigo,tipo_doc,num_doc,nombres,apellidos,fecha_nacimiento,fecha_afiliacion,telefono,email,estado) VALUES (?,?,?,?,?,?,CURDATE(),?,?,?)',
                      ['tmp' . bin2hex(random_bytes(6)), $c['tipo_doc'], $c['num_doc'], $c['nombres'], $c['apellidos'], $c['fecha_nacimiento'], $c['telefono'] ?: null, $c['email'] ?: null, 'ACTIVO']);
                    $id = (int)db()->lastInsertId();
                    q('UPDATE pacientes SET codigo=? WHERE id=?', ['AF-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT), $id]);
                    flash('Paciente afiliado.');
                }
                redirect('pacientes.php?a=ver&id=' . $id);
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') throw $ex;
                $err[] = 'Ya existe un paciente con ese documento.';
            }
        }
    }
    encabezado($id ? 'Editar paciente' : 'Afiliar paciente');
    errores($err);
    echo '<form method="post">' . csrf_field() . '<div class="grid">'
       . selecc('tipo_doc', 'Tipo de documento', ['DNI' => 'DNI', 'CE' => 'Carné de extranjería', 'PASAPORTE' => 'Pasaporte'], $d['tipo_doc'] ?? 'DNI')
       . campo('num_doc', 'Número de documento', $d['num_doc'] ?? '')
       . campo('nombres', 'Nombres', $d['nombres'] ?? '') . campo('apellidos', 'Apellidos', $d['apellidos'] ?? '')
       . campo('fecha_nacimiento', 'Fecha de nacimiento', $d['fecha_nacimiento'] ?? '', 'date')
       . campo('telefono', 'Teléfono (opcional)', $d['telefono'] ?? '') . campo('email', 'Correo (opcional)', $d['email'] ?? '', 'email')
       . ($id ? selecc('estado', 'Estado', ['ACTIVO' => 'ACTIVO', 'INACTIVO' => 'INACTIVO'], $d['estado']) : '')
       . '</div><p><button class="btn">Guardar</button> <a href="pacientes.php">Cancelar</a></p></form>';
    pie();
    exit;
}

if ($a === 'ver') {
    exigir('pacientes.ver');
    $d = q('SELECT * FROM pacientes WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch();
    if (!$d) pagina_error('Paciente no encontrado.');
    encabezado('Paciente ' . $d['codigo']);
    echo '<table>';
    foreach (['Código de afiliación' => 'codigo', 'Tipo de documento' => 'tipo_doc', 'Número' => 'num_doc', 'Nombres' => 'nombres', 'Apellidos' => 'apellidos',
              'Fecha de nacimiento' => 'fecha_nacimiento', 'Fecha de afiliación' => 'fecha_afiliacion', 'Teléfono' => 'telefono', 'Correo' => 'email', 'Estado' => 'estado'] as $l => $k)
        echo '<tr><th>' . $l . '</th><td>' . h($d[$k]) . '</td></tr>';
    echo '</table><p>';
    if (puede('pacientes.editar')) echo '<a class="btn" href="pacientes.php?a=editar&id=' . $d['id'] . '">Editar / cambiar estado</a> ';
    if (puede('ventas.crear') && $d['estado'] === 'ACTIVO') echo '<a class="btn" href="ventas.php?a=nueva&paciente=' . $d['id'] . '">Vender</a> ';
    echo '<a href="pacientes.php">Volver</a></p>';
    pie();
    exit;
}

exigir('pacientes.ver');
$qs = trim((string)($_GET['q'] ?? ''));
$es = (string)($_GET['estado'] ?? '');
$w = ['1=1'];
$p = [];
if ($qs !== '') { $w[] = '(num_doc LIKE ? OR nombres LIKE ? OR apellidos LIKE ? OR codigo LIKE ?)'; $p = array_fill(0, 4, "%$qs%"); }
if (in_array($es, ['ACTIVO', 'INACTIVO'], true)) { $w[] = 'estado=?'; $p[] = $es; }
$rows = q('SELECT * FROM pacientes WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 200', $p)->fetchAll();
encabezado('Pacientes afiliados');
echo '<form method="get" class="grid" style="grid-template-columns:2fr 1fr auto;align-items:end">'
   . campo('q', 'Buscar por documento o nombre', $qs)
   . selecc('estado', 'Estado', ['' => 'Todos', 'ACTIVO' => 'ACTIVO', 'INACTIVO' => 'INACTIVO'], $es)
   . '<label><button class="btn">Filtrar</button></label></form>';
if (puede('pacientes.crear')) echo '<p><a class="btn" href="pacientes.php?a=nuevo">+ Afiliar paciente</a></p>';
echo '<table><tr><th>Código</th><th>Documento</th><th>Paciente</th><th>Estado</th><th></th></tr>';
foreach ($rows as $r)
    echo '<tr><td>' . h($r['codigo']) . '</td><td>' . h($r['tipo_doc'] . ' ' . $r['num_doc']) . '</td><td>' . h($r['apellidos'] . ', ' . $r['nombres'])
       . '</td><td>' . h($r['estado']) . '</td><td><a href="pacientes.php?a=ver&id=' . $r['id'] . '">Ficha</a></td></tr>';
echo '</table>';
pie();
