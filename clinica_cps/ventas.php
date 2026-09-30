<?php
require __DIR__ . '/lib.php';
$a = $_GET['a'] ?? 'lista';

/* ========== NUEVA VENTA: seleccionar -> revisar total -> confirmar ========== */
if ($a === 'nueva') {
    $u = exigir('ventas.crear');
    $POST = $_SERVER['REQUEST_METHOD'] === 'POST';
    $accion = (string)($_POST['accion'] ?? '');
    $pac = (int)($_POST['paciente_id'] ?? $_GET['paciente'] ?? 0);
    $medio = (string)($_POST['medio_pago'] ?? 'EFECTIVO');
    $entrada = [];
    $cants = (array)($_POST['cant'] ?? []);
    foreach ((array)($_POST['pid'] ?? []) as $i => $p) $entrada[] = ['producto_id' => $p, 'cantidad' => $cants[$i] ?? ''];
    $err = [];
    $resumen = null;
    $lineas = [];
    if ($POST && $accion !== 'editar') {
        try {
            if (!in_array($medio, ['EFECTIVO', 'TARJETA'], true)) throw new VentaError('Medio de pago inválido.');
            $lineas = preparar_lineas($entrada);
            if ($accion === 'confirmar') {
                // el vendedor sale de la SESIÓN; precios y total los calcula el servidor con la BD
                $id = registrar_venta((int)$u['id'], $pac, $medio, $lineas);
                flash('Venta registrada correctamente.');
                redirect('ventas.php?a=ver&id=' . $id);
            }
            $resumen = calcular_venta($pac, $lineas);
        } catch (VentaError $ex) { $err[] = $ex->getMessage(); }
    }
    encabezado('Nueva venta');
    errores($err);
    if ($resumen) {
        [$pa, $det, $total] = $resumen;
        echo '<p>Paciente: <b>' . h($pa['codigo'] . ' - ' . $pa['apellidos'] . ', ' . $pa['nombres']) . '</b> · Pago: <b>' . h($medio) . '</b></p>'
           . '<table><tr><th>Producto</th><th class="r">Cant.</th><th class="r">P. unit.</th><th class="r">Subtotal</th></tr>';
        foreach ($det as $d)
            echo '<tr><td>' . h($d['nombre']) . '</td><td class="r">' . $d['cantidad'] . '</td><td class="r">' . money($d['pu']) . '</td><td class="r">' . money($d['sub']) . '</td></tr>';
        echo '<tr><th colspan="3" class="r">TOTAL</th><th class="r">' . money($total) . '</th></tr></table>';
        $ocultos = csrf_field() . '<input type="hidden" name="paciente_id" value="' . $pac . '"><input type="hidden" name="medio_pago" value="' . h($medio) . '">';
        foreach ($lineas as $pid => $cant) $ocultos .= '<input type="hidden" name="pid[]" value="' . $pid . '"><input type="hidden" name="cant[]" value="' . $cant . '">';
        echo '<form method="post" class="inline" onsubmit="this.querySelector(\'button\').disabled=true">' . $ocultos
           . '<input type="hidden" name="accion" value="confirmar"><button class="btn">Confirmar venta</button></form> '
           . '<form method="post" class="inline">' . $ocultos . '<input type="hidden" name="accion" value="editar"><button class="btn" style="background:#777">Modificar</button></form>';
    } else {
        $opts = '<option value="">-- producto --</option>';
        foreach (q("SELECT id,nombre,precio,stock FROM productos WHERE estado='ACTIVO' ORDER BY nombre")->fetchAll() as $p)
            $opts .= '<option value="' . $p['id'] . '">' . h($p['nombre'] . ' — ' . money(cents($p['precio'])) . ' (stock ' . $p['stock'] . ')') . '</option>';
        $pacOpts = ['' => '-- selecciona --'];
        foreach (q("SELECT id,codigo,nombres,apellidos,num_doc FROM pacientes WHERE estado='ACTIVO' ORDER BY apellidos")->fetchAll() as $p)
            $pacOpts[$p['id']] = $p['codigo'] . ' - ' . $p['apellidos'] . ', ' . $p['nombres'] . ' (' . $p['num_doc'] . ')';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="accion" value="revisar">'
           . selecc('paciente_id', 'Paciente (solo activos)', $pacOpts, $pac)
           . selecc('medio_pago', 'Medio de pago', ['EFECTIVO' => 'EFECTIVO', 'TARJETA' => 'TARJETA'], $medio)
           . '<table id="lineas"><thead><tr><th>Producto</th><th style="width:120px">Cantidad</th></tr></thead><tbody>';
        foreach ($entrada ?: [['producto_id' => '', 'cantidad' => '']] as $l) {
            $pid = is_scalar($l['producto_id']) ? (string)$l['producto_id'] : '';
            $cn = is_scalar($l['cantidad']) ? (string)$l['cantidad'] : '';
            echo '<tr><td><select name="pid[]">' . str_replace('value="' . h($pid) . '"', 'value="' . h($pid) . '" selected', $opts) . '</select></td>'
               . '<td><input type="number" name="cant[]" min="1" step="1" value="' . h($cn) . '"></td></tr>';
        }
        echo '</tbody></table><p><button type="button" class="btn" style="background:#777" onclick="var t=document.getElementById(\'lineas\').tBodies[0],r=t.rows[t.rows.length-1].cloneNode(true);r.querySelector(\'select\').value=\'\';r.querySelector(\'input\').value=\'\';t.appendChild(r)">+ Agregar producto</button></p>'
           . '<p><button class="btn">Revisar total</button></p></form>';
    }
    pie();
    exit;
}

/* ========== CONSULTA: administrador ve todas, vendedor solo las suyas ========== */
$u = requiere_login();
$todas = puede('ventas.ver_todas');
if (!$todas && !puede('ventas.ver_propias')) prohibido();
$alcance = $todas ? ['1=1', []] : ['v.usuario_id=?', [(int)$u['id']]];   // filtro impuesto por el SERVIDOR

if ($a === 'ver') {
    $v = q('SELECT v.*,pa.codigo AS pcod,pa.nombres,pa.apellidos,pa.num_doc,us.nombre AS vendedor
            FROM ventas v JOIN pacientes pa ON pa.id=v.paciente_id JOIN usuarios us ON us.id=v.usuario_id
            WHERE v.id=? AND ' . $alcance[0], array_merge([(int)($_GET['id'] ?? 0)], $alcance[1]))->fetch();
    if (!$v) pagina_error('Venta no encontrada.');   // misma respuesta exista o no: no expone datos ajenos
    $lin = q('SELECT d.*,p.nombre FROM venta_detalle d JOIN productos p ON p.id=d.producto_id WHERE d.venta_id=? ORDER BY d.id', [$v['id']])->fetchAll();
    encabezado('Venta ' . $v['codigo']);
    echo '<p>Fecha: <b>' . h($v['fecha_hora']) . '</b> · Paciente: <b>' . h($v['pcod'] . ' - ' . $v['apellidos'] . ', ' . $v['nombres']) . '</b> · Vendedor: <b>' . h($v['vendedor'])
       . '</b> · Pago: <b>' . h($v['medio_pago']) . '</b></p><table><tr><th>Producto</th><th class="r">Cant.</th><th class="r">P. unit. aplicado</th><th class="r">Subtotal</th></tr>';
    foreach ($lin as $l)
        echo '<tr><td>' . h($l['nombre']) . '</td><td class="r">' . (int)$l['cantidad'] . '</td><td class="r">' . money(cents($l['precio_unitario'])) . '</td><td class="r">' . money(cents($l['subtotal'])) . '</td></tr>';
    echo '<tr><th colspan="3" class="r">TOTAL</th><th class="r">' . money(cents($v['total'])) . '</th></tr></table><p><a href="ventas.php">Volver</a></p>';
    pie();
    exit;
}

$fecha_ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
$desde = (string)($_GET['desde'] ?? '');
$hasta = (string)($_GET['hasta'] ?? '');
$qs = trim((string)($_GET['q'] ?? ''));
$w = [$alcance[0]];
$p = $alcance[1];
if ($fecha_ok($desde)) { $w[] = 'v.fecha_hora>=?'; $p[] = $desde . ' 00:00:00'; }
if ($fecha_ok($hasta)) { $w[] = 'v.fecha_hora<=?'; $p[] = $hasta . ' 23:59:59'; }
if ($qs !== '') { $w[] = '(pa.nombres LIKE ? OR pa.apellidos LIKE ? OR pa.num_doc LIKE ? OR pa.codigo LIKE ?)'; array_push($p, ...array_fill(0, 4, "%$qs%")); }
$rows = q('SELECT v.id,v.codigo,v.fecha_hora,v.medio_pago,v.total,pa.nombres,pa.apellidos,us.nombre AS vendedor
           FROM ventas v JOIN pacientes pa ON pa.id=v.paciente_id JOIN usuarios us ON us.id=v.usuario_id
           WHERE ' . implode(' AND ', $w) . ' ORDER BY v.id DESC LIMIT 200', $p)->fetchAll();
encabezado($todas ? 'Ventas (todas)' : 'Mis ventas');
echo '<form method="get" class="grid" style="grid-template-columns:1fr 1fr 2fr auto;align-items:end">'
   . campo('desde', 'Desde', $desde, 'date') . campo('hasta', 'Hasta', $hasta, 'date') . campo('q', 'Paciente (documento, código o nombre)', $qs)
   . '<label><button class="btn">Filtrar</button></label></form>';
$suma = 0;
echo '<table><tr><th>Código</th><th>Fecha</th><th>Paciente</th><th>Vendedor</th><th>Pago</th><th class="r">Total</th><th></th></tr>';
foreach ($rows as $r) {
    $suma += cents($r['total']);
    echo '<tr><td>' . h($r['codigo']) . '</td><td>' . h($r['fecha_hora']) . '</td><td>' . h($r['apellidos'] . ', ' . $r['nombres']) . '</td><td>' . h($r['vendedor'])
       . '</td><td>' . h($r['medio_pago']) . '</td><td class="r">' . money(cents($r['total'])) . '</td><td><a href="ventas.php?a=ver&id=' . $r['id'] . '">Detalle</a></td></tr>';
}
echo '<tr><th colspan="5" class="r">Total del listado (' . count($rows) . ' ventas)</th><th class="r">' . money($suma) . '</th><th></th></tr></table>';
pie();
