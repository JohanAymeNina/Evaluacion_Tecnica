<?php
date_default_timezone_set('America/Lima');
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* ---------- Configuración y base de datos ---------- */
function cfg(string $k, string $def = ''): string {
    static $env = null;
    if ($env === null) {
        $f = __DIR__ . '/.env';
        $env = is_file($f) ? (parse_ini_file($f) ?: []) : [];
    }
    $v = getenv($k);
    return $v !== false ? $v : (string)($env[$k] ?? $def);
}

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . cfg('DB_HOST', '127.0.0.1') . ';dbname=' . cfg('DB_NAME', 'clinica_cps') . ';charset=utf8mb4',
            cfg('DB_USER', 'root'), cfg('DB_PASS', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

/* Consulta parametrizada (evita inyección SQL) */
function q(string $sql, array $p = []): PDOStatement {
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}

/* ---------- Utilidades ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }   // escape de salida
function redirect(string $u) { header('Location: ' . $u); exit; }
function flash(?string $msg = null, string $tipo = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function cents($v): int { return (int)round(((float)$v) * 100); }
function money(int $c): string { return 'S/ ' . number_format($c / 100, 2); }
function dec(int $c): string { return number_format($c / 100, 2, '.', ''); }

/* ---------- CSRF ---------- */
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="_t" value="' . csrf() . '">'; }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf(), (string)($_POST['_t'] ?? ''))) {
        http_response_code(419);
        exit('Token CSRF inválido. Vuelve atrás y recarga la página.');
    }
}

/* ---------- Sesión, usuario y permisos ---------- */
function cerrar_sesion(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* Usuario actual: se vuelve a leer de la BD en CADA petición.
   Si fue desactivado, la sesión previa deja de servir. */
function usuario(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $r = q("SELECT u.id,u.nombre,u.email,u.estado,r.nombre AS rol
                    FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?", [$_SESSION['uid']])->fetch();
            if ($r && $r['estado'] === 'ACTIVO') $u = $r; else cerrar_sesion();
        }
    }
    return $u;
}

/* Permisos leídos de la BD en cada llamada: quitar un permiso surte efecto en la siguiente petición */
function tiene_permiso(int $uid, string $codigo): bool {
    return (bool)q("SELECT 1 FROM usuarios u
                    JOIN rol_permiso rp ON rp.rol_id=u.rol_id
                    JOIN permisos p ON p.id=rp.permiso_id
                    WHERE u.id=? AND u.estado='ACTIVO' AND p.codigo=?", [$uid, $codigo])->fetchColumn();
}
function puede(string $perm): bool {
    $u = usuario();
    return $u ? tiene_permiso((int)$u['id'], $perm) : false;
}
function requiere_login(): array {
    $u = usuario();
    if (!$u) redirect('login.php');
    csrf_check();
    return $u;
}
/* Guardia del SERVIDOR: cada página/acción llama a exigir('modulo.accion') */
function exigir(string $perm): array {
    $u = requiere_login();
    if (!tiene_permiso((int)$u['id'], $perm)) prohibido();
    return $u;
}
function prohibido() { pagina_error('No tienes permiso para esta acción.', 403); }
function pagina_error(string $m, int $c = 404) {
    http_response_code($c);
    encabezado("Error $c");
    echo '<div class="msg err">' . h($m) . '</div>';
    pie();
    exit;
}

/* ---------- Plantilla HTML ---------- */
function encabezado(string $titulo): void {
    $u = usuario();
    $f = flash();
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($titulo) . ' - Clínica</title><style>
body{font:15px system-ui,sans-serif;margin:0;background:#f4f6f8;color:#222}
nav{background:#0b5c7a;padding:10px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
nav a{color:#fff;text-decoration:none}
main{max-width:1000px;margin:20px auto;background:#fff;padding:20px;border-radius:8px}
table{width:100%;border-collapse:collapse;margin:10px 0}th,td{border-bottom:1px solid #ddd;padding:6px;text-align:left}
.msg{padding:8px 12px;border-radius:6px;margin:10px 0}.ok{background:#d9f2e3}.err{background:#fbdcdc}
label{display:block;margin:6px 0}input,select,textarea{padding:6px;width:100%;box-sizing:border-box}
input[type=checkbox]{width:auto}form.inline{display:inline}
.btn{background:#0b5c7a;color:#fff;border:0;padding:7px 14px;border-radius:5px;cursor:pointer;text-decoration:none;display:inline-block}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.r{text-align:right}.tag{font-size:12px;padding:2px 6px;border-radius:4px;background:#eee}
</style></head><body>';
    if ($u) {
        echo '<nav><a href="index.php"><b>Clínica</b></a>';
        foreach ([['pacientes.ver', 'pacientes.php', 'Pacientes'], ['productos.ver', 'productos.php', 'Productos'],
                  ['ventas.crear', 'ventas.php?a=nueva', 'Nueva venta'], ['usuarios.ver', 'usuarios.php', 'Usuarios'],
                  ['roles.ver', 'roles.php', 'Roles']] as [$p, $url, $l])
            if (puede($p)) echo '<a href="' . $url . '">' . $l . '</a>';
        if (puede('ventas.ver_todas') || puede('ventas.ver_propias')) echo '<a href="ventas.php">Ventas</a>';
        echo '<span style="margin-left:auto">' . h($u['nombre']) . ' (' . h($u['rol']) . ')</span>'
           . '<form class="inline" method="post" action="logout.php">' . csrf_field() . '<button class="btn">Salir</button></form></nav>';
    }
    echo '<main><h1>' . h($titulo) . '</h1>';
    if ($f) echo '<div class="msg ' . h($f[1]) . '">' . h($f[0]) . '</div>';
}
function pie(): void { echo '</main></body></html>'; }

function campo(string $n, string $et, $v = '', string $t = 'text', string $x = ''): string {
    return '<label>' . h($et) . '<input type="' . $t . '" name="' . $n . '" value="' . h($v) . '" ' . $x . '></label>';
}
function selecc(string $n, string $et, array $op, $sel): string {
    $s = '<label>' . h($et) . '<select name="' . $n . '">';
    foreach ($op as $k => $t) $s .= '<option value="' . h($k) . '"' . ((string)$k === (string)$sel ? ' selected' : '') . '>' . h($t) . '</option>';
    return $s . '</select></label>';
}
function errores(array $e): void { foreach ($e as $m) echo '<div class="msg err">' . h($m) . '</div>'; }

/* ---------- Lógica de ventas ---------- */
class VentaError extends RuntimeException {}

/* Junta las líneas y SUMA cantidades del mismo producto (para validar stock) */
function preparar_lineas(array $entrada): array {
    $tot = [];
    foreach ($entrada as $l) {
        $pid = is_scalar($l['producto_id'] ?? null) ? trim((string)$l['producto_id']) : '';
        $cant = is_scalar($l['cantidad'] ?? null) ? trim((string)$l['cantidad']) : '';
        if ($pid === '' && $cant === '') continue;
        if (!ctype_digit($pid) || (int)$pid < 1) throw new VentaError('Selecciona un producto válido en cada línea.');
        if (!ctype_digit($cant) || strlen($cant) > 6 || (int)$cant < 1) throw new VentaError('La cantidad debe ser un entero mayor que cero.');
        $tot[(int)$pid] = ($tot[(int)$pid] ?? 0) + (int)$cant;
    }
    if (!$tot) throw new VentaError('Agrega al menos un producto.');
    return $tot;
}

/* Valida paciente/productos/stock y calcula con PRECIOS DE LA BD (nunca del navegador) */
function calcular_venta(int $paciente_id, array $lineas, bool $bloquear = false): array {
    $lock = $bloquear ? ' FOR UPDATE' : '';
    $pa = q('SELECT id,nombres,apellidos,codigo,estado FROM pacientes WHERE id=?' . $lock, [$paciente_id])->fetch();
    if (!$pa || $pa['estado'] !== 'ACTIVO') throw new VentaError('Selecciona un paciente ACTIVO.');
    $det = [];
    $total = 0;
    ksort($lineas);   // orden fijo de bloqueo
    foreach ($lineas as $pid => $cant) {
        $pr = q('SELECT id,nombre,precio,stock,estado FROM productos WHERE id=?' . $lock, [$pid])->fetch();
        if (!$pr || $pr['estado'] !== 'ACTIVO') throw new VentaError('El producto seleccionado no existe o está inactivo.');
        if ($cant > (int)$pr['stock'])
            throw new VentaError("Stock insuficiente para «{$pr['nombre']}»: disponible {$pr['stock']}, solicitado $cant.");
        $pu = cents($pr['precio']);
        $det[] = ['producto_id' => (int)$pr['id'], 'nombre' => $pr['nombre'], 'cantidad' => $cant, 'pu' => $pu, 'sub' => $pu * $cant];
        $total += $pu * $cant;
    }
    return [$pa, $det, $total];
}

/* Cabecera + detalles + descuento de stock en UNA transacción. $fallar_tras solo sirve para la prueba del caso 10 */
function registrar_venta(int $vendedor_id, int $paciente_id, string $medio, array $lineas, ?int $fallar_tras = null): int {
    if (!in_array($medio, ['EFECTIVO', 'TARJETA'], true)) throw new VentaError('Medio de pago inválido.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        [, $det, $total] = calcular_venta($paciente_id, $lineas, true);   // FOR UPDATE: evita ventas simultáneas
        q('INSERT INTO ventas (codigo,paciente_id,usuario_id,medio_pago,total) VALUES (?,?,?,?,?)',
          ['tmp' . bin2hex(random_bytes(6)), $paciente_id, $vendedor_id, $medio, dec($total)]);
        $id = (int)$pdo->lastInsertId();
        q('UPDATE ventas SET codigo=? WHERE id=?', ['V-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT), $id]);
        foreach ($det as $i => $d) {
            if ($fallar_tras !== null && $i >= $fallar_tras) throw new RuntimeException('Fallo simulado');
            q('INSERT INTO venta_detalle (venta_id,producto_id,cantidad,precio_unitario,subtotal) VALUES (?,?,?,?,?)',
              [$id, $d['producto_id'], $d['cantidad'], dec($d['pu']), dec($d['sub'])]);
            $s = q('UPDATE productos SET stock=stock-? WHERE id=? AND stock>=?', [$d['cantidad'], $d['producto_id'], $d['cantidad']]);
            if ($s->rowCount() !== 1) throw new VentaError('Stock insuficiente.');
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
