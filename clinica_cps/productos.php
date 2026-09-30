<?php
require __DIR__ . '/lib.php';
$a = $_GET['a'] ?? 'lista';

function validar_producto(array $d, ?int $id): array {
    $e = [];
    $c = ['codigo' => strtoupper(trim((string)($d['codigo'] ?? ''))), 'nombre' => trim((string)($d['nombre'] ?? '')),
          'descripcion' => trim((string)($d['descripcion'] ?? '')), 'precio' => str_replace(',', '.', trim((string)($d['precio'] ?? ''))),
          'stock' => trim((string)($d['stock'] ?? '0')), 'estado' => (string)($d['estado'] ?? 'ACTIVO')];
    if (!preg_match('/^[A-Z0-9-]{2,30}$/', $c['codigo'])) $e[] = 'Código inválido (2 a 30 letras, números o guion).';
    if ($c['nombre'] === '' || mb_strlen($c['nombre']) > 100) $e[] = 'El nombre es obligatorio (máx. 100).';
    if (mb_strlen($c['descripcion']) > 255) $e[] = 'La descripción admite máx. 255 caracteres.';
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $c['precio']) || (float)$c['precio'] <= 0) $e[] = 'El precio debe ser mayor que cero (máx. 2 decimales).';
    if ($id === null && (!ctype_digit($c['stock']) || strlen($c['stock']) > 7)) $e[] = 'El stock inicial debe ser un entero mayor o igual a cero.';
    if (!in_array($c['estado'], ['ACTIVO', 'INACTIVO'], true)) $e[] = 'Estado inválido.';
    if (!$e && q('SELECT 1 FROM productos WHERE codigo=? AND id<>?', [$c['codigo'], $id ?? 0])->fetchColumn()) $e[] = 'Ya existe un producto con ese código.';
    return [$e, $c];
}

if ($a === 'nuevo' || $a === 'editar') {
    $id = $a === 'editar' ? (int)($_GET['id'] ?? 0) : null;
    exigir($id ? 'productos.editar' : 'productos.crear');
    $d = $id ? q('SELECT * FROM productos WHERE id=?', [$id])->fetch() : ['estado' => 'ACTIVO', 'stock' => '0'];
    if (!$d) pagina_error('Producto no encontrado.');
    $err = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        [$err, $c] = validar_producto($_POST, $id);
        $d = array_merge($d, $c);
        if (!$err) {
            try {
                if ($id) {   // el stock NO se toca al editar: solo cambia con las ventas
                    q('UPDATE productos SET codigo=?,nombre=?,descripcion=?,precio=?,estado=? WHERE id=?',
                      [$c['codigo'], $c['nombre'], $c['descripcion'] ?: null, $c['precio'], $c['estado'], $id]);
                    flash('Producto actualizado.');
                } else {
                    q('INSERT INTO productos (codigo,nombre,descripcion,precio,stock,estado) VALUES (?,?,?,?,?,?)',
                      [$c['codigo'], $c['nombre'], $c['descripcion'] ?: null, $c['precio'], (int)$c['stock'], $c['estado']]);
                    flash('Producto creado.');
                }
                redirect('productos.php');
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') throw $ex;
                $err[] = 'Ya existe un producto con ese código.';
            }
        }
    }
    encabezado($id ? 'Editar producto' : 'Nuevo producto');
    errores($err);
    echo '<form method="post"><div class="grid">' . csrf_field()
       . campo('codigo', 'Código', $d['codigo'] ?? '') . campo('nombre', 'Nombre', $d['nombre'] ?? '')
       . campo('descripcion', 'Descripción (opcional)', $d['descripcion'] ?? '') . campo('precio', 'Precio de venta (S/)', $d['precio'] ?? '')
       . ($id ? '<label>Stock actual (solo cambia con ventas)<input value="' . h($d['stock']) . '" disabled></label>'
              : campo('stock', 'Stock inicial', $d['stock'] ?? '0', 'number', 'min="0" step="1"'))
       . selecc('estado', 'Estado', ['ACTIVO' => 'ACTIVO', 'INACTIVO' => 'INACTIVO'], $d['estado'])
       . '</div><p><button class="btn">Guardar</button> <a href="productos.php">Cancelar</a></p></form>';
    pie();
    exit;
}

exigir('productos.ver');
$qs = trim((string)($_GET['q'] ?? ''));
$es = (string)($_GET['estado'] ?? '');
$w = ['1=1'];
$p = [];
if ($qs !== '') { $w[] = '(codigo LIKE ? OR nombre LIKE ?)'; $p = ["%$qs%", "%$qs%"]; }
if (in_array($es, ['ACTIVO', 'INACTIVO'], true)) { $w[] = 'estado=?'; $p[] = $es; }
$rows = q('SELECT * FROM productos WHERE ' . implode(' AND ', $w) . ' ORDER BY id', $p)->fetchAll();
encabezado('Productos');
echo '<form method="get" class="grid" style="grid-template-columns:2fr 1fr auto;align-items:end">'
   . campo('q', 'Buscar por código o nombre', $qs)
   . selecc('estado', 'Estado', ['' => 'Todos', 'ACTIVO' => 'ACTIVO', 'INACTIVO' => 'INACTIVO'], $es)
   . '<label><button class="btn">Filtrar</button></label></form>';
if (puede('productos.crear')) echo '<p><a class="btn" href="productos.php?a=nuevo">+ Nuevo producto</a></p>';
echo '<table><tr><th>Código</th><th>Producto</th><th class="r">Precio</th><th class="r">Stock</th><th>Estado</th><th></th></tr>';
foreach ($rows as $r)
    echo '<tr><td>' . h($r['codigo']) . '</td><td>' . h($r['nombre']) . '</td><td class="r">' . money(cents($r['precio'])) . '</td><td class="r">' . (int)$r['stock']
       . '</td><td>' . h($r['estado']) . '</td><td>' . (puede('productos.editar') ? '<a href="productos.php?a=editar&id=' . $r['id'] . '">Editar</a>' : '') . '</td></tr>';
echo '</table>';
pie();
