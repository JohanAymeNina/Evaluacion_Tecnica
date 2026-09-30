<?php
/* Pruebas automatizadas. Uso:  php tests/run.php   (usa la BD aparte "clinica_test", la recrea sola) */
if (PHP_SAPI !== 'cli') exit('Solo por consola.');
putenv('DB_NAME=clinica_test');
require __DIR__ . '/../lib.php';
require __DIR__ . '/../instalar.php';
instalar_bd('clinica_test', true);

$fallas = 0;
function prueba(string $n, callable $f): void {
    global $fallas;
    try { $f(); echo "OK     $n\n"; } catch (Throwable $e) { $fallas++; echo "FALLA  $n -> " . $e->getMessage() . "\n"; }
}
function afirmar($c, string $m = 'Aserción falsa'): void { if (!$c) throw new Exception($m); }
function uid(string $email): int { return (int)q('SELECT id FROM usuarios WHERE email=?', [$email])->fetchColumn(); }
function prod(string $cod): array { return q('SELECT * FROM productos WHERE codigo=?', [$cod])->fetch(); }
function pac(): int { return (int)q("SELECT id FROM pacientes WHERE num_doc='70000001'")->fetchColumn(); }
function nventas(): int { return (int)q('SELECT COUNT(*) FROM ventas')->fetchColumn(); }

prueba('Caso 06: stock insuficiente (6+5 de un producto con stock 10) rechaza todo', function () {
    $b = prod('P001');
    $lineas = preparar_lineas([['producto_id' => $b['id'], 'cantidad' => '6'], ['producto_id' => $b['id'], 'cantidad' => '5']]);
    try { registrar_venta(uid('vendedor1@clinica.test'), pac(), 'EFECTIVO', $lineas); afirmar(false, 'Debió rechazar la venta'); }
    catch (VentaError $e) { afirmar(str_contains($e->getMessage(), 'Stock insuficiente')); }
    afirmar((int)prod('P001')['stock'] === 10, 'El stock no debe cambiar');
    afirmar(nventas() === 0, 'No debe quedar ninguna venta');
});

prueba('Caso 05: venta correcta -> total S/ 57.50 y stock 7 / 4', function () {
    $lineas = preparar_lineas([['producto_id' => prod('P001')['id'], 'cantidad' => '3'], ['producto_id' => prod('P002')['id'], 'cantidad' => '1']]);
    $vid = registrar_venta(uid('vendedor1@clinica.test'), pac(), 'EFECTIVO', $lineas);
    $v = q('SELECT * FROM ventas WHERE id=?', [$vid])->fetch();
    afirmar($v['total'] === '57.50', 'Total esperado 57.50, fue ' . $v['total']);
    afirmar((int)$v['usuario_id'] === uid('vendedor1@clinica.test'), 'Debe quedar asociada al vendedor');
    afirmar((int)prod('P001')['stock'] === 7 && (int)prod('P002')['stock'] === 4, 'Stock esperado 7 y 4');
    afirmar((int)q('SELECT COUNT(*) FROM venta_detalle WHERE venta_id=?', [$vid])->fetchColumn() === 2, 'Deben existir 2 detalles');
});

prueba('Caso 03: permiso denegado (vendedor sin usuarios.ver; al quitar ventas.crear queda bloqueado)', function () {
    $v = uid('vendedor1@clinica.test');
    afirmar(!tiene_permiso($v, 'usuarios.ver'), 'El vendedor no debe gestionar usuarios');
    afirmar(tiene_permiso($v, 'ventas.crear'), 'Antes de quitarlo sí puede vender');
    q("DELETE rp FROM rol_permiso rp JOIN permisos p ON p.id=rp.permiso_id JOIN roles r ON r.id=rp.rol_id WHERE r.nombre='Vendedor' AND p.codigo='ventas.crear'");
    afirmar(!tiene_permiso($v, 'ventas.crear'), 'Sin el permiso debe quedar bloqueado');
    q("INSERT INTO rol_permiso (rol_id,permiso_id) SELECT r.id,p.id FROM roles r,permisos p WHERE r.nombre='Vendedor' AND p.codigo='ventas.crear'");
});

prueba('Caso 10: fallo entre cabecera y detalle revierte venta y stock', function () {
    $antes = nventas();
    $s1 = (int)prod('P001')['stock'];
    $s2 = (int)prod('P002')['stock'];
    $lineas = preparar_lineas([['producto_id' => prod('P001')['id'], 'cantidad' => '1'], ['producto_id' => prod('P002')['id'], 'cantidad' => '1']]);
    try { registrar_venta(uid('vendedor1@clinica.test'), pac(), 'TARJETA', $lineas, 1); afirmar(false, 'Debió fallar'); }
    catch (RuntimeException $e) { afirmar($e->getMessage() === 'Fallo simulado'); }
    afirmar(nventas() === $antes, 'No debe quedar venta parcial');
    afirmar((int)prod('P001')['stock'] === $s1 && (int)prod('P002')['stock'] === $s2, 'El stock debe quedar intacto');
});

prueba('Caso 08: cambiar el precio no altera el detalle histórico', function () {
    q("UPDATE productos SET precio=99.90 WHERE codigo='P001'");
    $pu = q("SELECT d.precio_unitario FROM venta_detalle d JOIN productos p ON p.id=d.producto_id WHERE p.codigo='P001' LIMIT 1")->fetchColumn();
    afirmar($pu === '12.50', 'Debe conservar 12.50, fue ' . $pu);
});

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodas las pruebas pasaron\n";
exit($fallas ? 1 : 0);
