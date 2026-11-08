<?php
require_once 'process.php';
header('Content-Type: application/json; charset=utf-8');

function out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
function need(string ...$roles): void {
    if (!in_array(currentRole(), $roles, true)) out(['error' => 'Your role is not allowed to do this.'], 403);
}
function productJson(array $p): array {
    return ['id' => (int)$p['id'], 'name' => $p['name'], 'category_id' => (int)$p['category_id'], 'category' => $p['category'],
            'unit' => $p['unit'], 'reorder_level' => (int)$p['reorder_level'], 'current_stock' => (int)$p['stock'],
            'unit_cost' => (float)$p['unit_cost'], 'active' => (bool)$p['active'], 'low_stock' => isLow($p), 'updated_at' => $p['updated_at']];
}

$method   = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$id       = (int)($_GET['id'] ?? 0);
$body     = json_decode(file_get_contents('php://input'), true);
$body     = is_array($body) ? $body : [];

if ($resource === 'login' && $method === 'POST') {
    if (!attemptLogin((string)($body['username'] ?? ''), (string)($body['password'] ?? ''))) out(['error' => 'Wrong username or password.'], 401);
    out(['user' => ['name' => $_SESSION['name'], 'username' => $_SESSION['username'], 'role' => $_SESSION['role']], 'csrf' => $_SESSION['csrf']]);
}
if (!isLoggedIn()) out(['error' => 'Not logged in. POST api.php?resource=login first.'], 401);
if ($resource === 'logout' && $method === 'POST') { logoutUser(); out(['message' => 'Logged out.']); }
if ($method !== 'GET' && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    out(['error' => 'Missing or wrong X-CSRF-Token header.'], 403);
}

try {
  
    if ($resource === 'products') {
        if ($method === 'GET') {
            if ($id) {
                $p = getProduct($id);
                if (!$p) out(['error' => 'Product not found.'], 404);
                out(productJson($p));
            }
            $where = '1=1'; $args = [];
            if (!empty($_GET['q']))           { $where .= ' AND (p.product_name LIKE ? OR c.category_name LIKE ?)'; $args[] = '%' . $_GET['q'] . '%'; $args[] = '%' . $_GET['q'] . '%'; }
            if (!empty($_GET['category_id'])) { $where .= ' AND p.category_id = ?'; $args[] = (int)$_GET['category_id']; }
            if (!empty($_GET['low']))         { $where .= ' AND p.is_active = 1 AND p.current_stock <= p.reorder_level'; }
            $s = $pdo->prepare(productSql($where));
            $s->execute($args);
            out(array_map('productJson', $s->fetchAll()));
        }
        need('Admin', 'Manager');
        if ($method === 'POST') out(productJson(getProduct(saveProduct($body))), 201);
        if (!$id || !getProduct($id)) out(['error' => 'Product not found.'], 404);
        if ($method === 'PUT') out(productJson(getProduct(saveProduct($body, $id))));
        if ($method === 'DELETE') {
            $pdo->prepare('UPDATE products SET is_active = 0 WHERE product_id = ?')->execute([$id]);
            logAct('Deactivated product #' . $id . ' (API)');
            out(['message' => 'Product removed from use. Its history is kept.']);
        }
    }

    
    if ($resource === 'stock_in') {
        need('Admin', 'Manager');
        if ($method === 'GET') {
            out($pdo->query("SELECT s.stock_in_id id, s.product_id, p.product_name product, s.quantity_received quantity, s.expiry_date, s.supplier_id, s.po_id, s.received_at
                             FROM stock_in s JOIN products p ON p.product_id = s.product_id ORDER BY s.received_at DESC, s.stock_in_id DESC LIMIT 50")->fetchAll());
        }
        if ($method === 'POST') {
            $r = recordStockIn($body);
            out(['message' => 'Delivery recorded.', 'product' => productJson($r['product'])], 201);
        }
    }

    
    if ($resource === 'stock_out') {
        if ($method === 'GET') {
            out($pdo->query("SELECT o.stock_out_id id, o.product_id, p.product_name product, o.quantity_used quantity, o.reason, o.note, o.status, o.used_at
                             FROM stock_out o JOIN products p ON p.product_id = o.product_id ORDER BY o.used_at DESC, o.stock_out_id DESC LIMIT 50")->fetchAll());
        }
        if ($method === 'POST') {
            $r = recordStockOut($body);
            out(['message' => $r['pending'] ? 'Correction saved and waiting for approval.' : 'Stock-out recorded.', 'pending' => $r['pending'],
                 'warning' => trim($r['warning']), 'product' => productJson(getProduct($r['product']['id']))], 201);
        }
    }
} catch (InventoryException $ex) {
    out(['error' => $ex->getMessage()], 422);
}
out(['error' => 'Unknown resource or method.'], 404);
