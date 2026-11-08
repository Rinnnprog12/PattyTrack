<?php
// All logic lives here: helpers, shared business rules, then one block per form action.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once 'config.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

const REASONS = ['Sale' => 'Used to make customer orders',
                 'Spoilage' => 'Expired or gone bad',
                 'Waste' => 'Dropped, burnt or made by mistake',
                 'Correction' => 'Fix a count that does not match (staff entries need approval)'];

// Thrown by the business rules below when input is not allowed (forms show it, the API returns 422)
class InventoryException extends Exception {}

/* ---------------- helpers ---------------- */
// Logged in = session keys exist AND the account is still active. The role is re-read from the database on
// every request, so a deactivated user is kicked out and a role change takes effect immediately.
function isLoggedIn(): bool {
    static $valid = null;
    if (empty($_SESSION['user_id']) || empty($_SESSION['username']) || empty($_SESSION['role'])) return false;
    if ($valid === null) {
        global $pdo;
        $s = $pdo->prepare('SELECT role, status FROM users WHERE user_id = ?');
        $s->execute([$_SESSION['user_id']]);
        $u = $s->fetch();
        if ($u && $u['status'] === 'active') {
            $_SESSION['role'] = $u['role'];
            $valid = true;
        } else {
            unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['name']);
            $valid = false;
        }
    }
    return $valid;
}
function currentRole(): ?string { return isLoggedIn() ? $_SESSION['role'] : null; }
function requireRole(string ...$roles): void {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if (!isLoggedIn()) { header('Location: login.php'); exit; }
    if (!in_array(currentRole(), $roles, true)) {
        http_response_code(403);
        exit('403 Forbidden - your role is not allowed to do this.');
    }
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function peso($n, int $d = 2): string { return '₱' . number_format((float)$n, $d); }
function poCode($id): string { return 'PO-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }
function validDate(string $d): bool { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; }

// CSRF: every form carries a secret token; a POST without it is refused
function csrfField(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function checkCsrf(?string $token): void {
    if (!hash_equals($_SESSION['csrf'], (string)$token)) {
        http_response_code(403);
        exit('403 Forbidden - security token missing or expired. Go back, refresh the page and try again.');
    }
}

// flash message shown once at the top of the next page: type = ok | warn | err
function flash(string $text, string $type = 'ok'): void { $_SESSION['flash'] = ['text' => $text, 'type' => $type]; }
function done(string $url, string $text, string $type = 'ok'): void { flash($text, $type); redirect($url); }
function logAct(string $text): void {
    global $pdo;
    $pdo->prepare('INSERT INTO activity_logs (user_id, action_taken) VALUES (?,?)')->execute([$_SESSION['user_id'], $text]);
}

function attemptLogin(string $username, string $password): bool {
    global $pdo;
    $stmt = $pdo->prepare('SELECT user_id, full_name, username, password, role, status FROM users WHERE username = ?');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) return false;
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['name']     = $user['full_name'];
    $_SESSION['role']     = $user['role'];
    logAct('Logged in');
    return true;
}
function logoutUser(): void {
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    session_destroy();
}

/* ---------------- data helpers ---------------- */
// Products with friendly column names: id, name, unit, stock, active, category ...
function productSql(string $where = '1=1', string $order = 'p.product_name'): string {
    return "SELECT p.product_id id, p.product_name name, p.unit_of_measure unit, p.reorder_level, p.unit_cost,
                   p.current_stock stock, p.is_active active, p.updated_at, p.category_id, c.category_name category
            FROM products p JOIN categories c ON c.category_id = p.category_id WHERE $where ORDER BY $order";
}
function getProduct(int $id): ?array {
    global $pdo;
    $s = $pdo->prepare(productSql('p.product_id = ?'));
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function categoryList(): array {
    global $pdo;
    return $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_name')->fetchAll();
}
// "Low" = active product at or below its reorder level
function isLow(array $p): bool { return $p['active'] && $p['stock'] <= $p['reorder_level']; }
function lowProducts(): array {
    global $pdo;
    return $pdo->query(productSql('p.is_active = 1 AND p.current_stock <= p.reorder_level',
                                  'p.current_stock / GREATEST(p.reorder_level, 1)'))->fetchAll();
}
// Open purchase-order lines for a product (to show "already on order")
function onOrder(int $productId): array {
    global $pdo;
    $s = $pdo->prepare("SELECT po.po_id id, i.quantity_ordered quantity FROM purchase_order_items i
                        JOIN purchase_orders po ON po.po_id = i.po_id
                        WHERE po.status = 'Pending' AND i.product_id = ?");
    $s->execute([$productId]);
    return $s->fetchAll();
}
function lowWarning(int $productId): string {
    $p = getProduct($productId);
    if (!$p || !isLow($p)) return '';
    return ' Warning: ' . $p['name'] . ($p['stock'] == 0 ? ' is out of stock.' : " is low ({$p['stock']} {$p['unit']} left, reorder at {$p['reorder_level']}).");
}
// Number of things waiting for someone to act (shown as badges in the menu)
function awaitingPoCount(): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending' AND approved_by IS NULL")->fetchColumn();
}
function pendingCorrectionCount(): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM stock_out WHERE status = 'pending'")->fetchColumn();
}
// Value of stock-in / stock-out per day, for the bar charts
function dailySeries(string $from, string $to): array {
    global $pdo;
    $sum = [];
    $queries = ['in'  => "SELECT DATE(received_at) d, SUM(quantity_received * unit_cost) v FROM stock_in s JOIN products p ON p.product_id = s.product_id
                          WHERE received_at >= ? AND received_at < DATE_ADD(?, INTERVAL 1 DAY) GROUP BY d",
                'out' => "SELECT DATE(used_at) d, SUM(quantity_used * unit_cost) v FROM stock_out s JOIN products p ON p.product_id = s.product_id
                          WHERE status = 'approved' AND used_at >= ? AND used_at < DATE_ADD(?, INTERVAL 1 DAY) GROUP BY d"];
    foreach ($queries as $type => $sql) {
        $s = $pdo->prepare($sql);
        $s->execute([$from, $to]);
        foreach ($s->fetchAll() as $r) $sum[$r['d']][$type] = (float)$r['v'];
    }
    $out = ['labels' => [], 'in' => [], 'out' => []];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $out['labels'][] = date('M j', $t);
        $out['in'][]  = round($sum[$d]['in']  ?? 0, 2);
        $out['out'][] = round($sum[$d]['out'] ?? 0, 2);
    }
    return $out;
}

/* ---------------- business rules (used by the forms AND the REST API) ---------------- */
// Add ($id = 0) or update a product. Returns the product id.
function saveProduct(array $in, int $id = 0): int {
    global $pdo;
    $name    = trim((string)($in['name'] ?? ''));
    $catId   = (int)($in['category_id'] ?? 0);
    $unit    = trim((string)($in['unit'] ?? ''));
    $reorder = $in['reorder_level'] ?? '';
    $cost    = $in['unit_cost'] ?? '';
    $stock   = $in['stock'] ?? 0;

    if ($name === '' || $unit === '') throw new InventoryException('Enter a product name and a unit of measure.');
    $c = $pdo->prepare('SELECT 1 FROM categories WHERE category_id = ?');
    $c->execute([$catId]);
    if (!$c->fetch()) throw new InventoryException('Choose a category.');
    if (!ctype_digit((string)$reorder)) throw new InventoryException('Reorder level must be a whole number, 0 or more.');
    if (!is_numeric($cost) || $cost < 0) throw new InventoryException('Enter the cost per unit.');
    if (!$id && !ctype_digit((string)$stock)) throw new InventoryException('Starting stock must be a whole number, 0 or more.');
    $dup = $pdo->prepare('SELECT 1 FROM products WHERE product_name = ? AND product_id <> ?');
    $dup->execute([$name, $id]);
    if ($dup->fetch()) throw new InventoryException('A product with this name already exists.');

    if ($id) {
        if (!getProduct($id)) throw new InventoryException('Product not found.');
        $pdo->prepare('UPDATE products SET product_name=?, category_id=?, unit_of_measure=?, reorder_level=?, unit_cost=? WHERE product_id=?')
            ->execute([$name, $catId, $unit, $reorder, $cost, $id]);
        logAct("Updated product $name");
        return $id;
    }
    $pdo->prepare('INSERT INTO products (product_name, category_id, unit_of_measure, reorder_level, unit_cost, current_stock) VALUES (?,?,?,?,?,?)')
        ->execute([$name, $catId, $unit, $reorder, $cost, $stock]);
    $newId = (int)$pdo->lastInsertId();   // read it before logAct(), which inserts another row
    logAct("Added product $name");
    return $newId;
}

// Record a delivery and add it to the product's stock. Returns the product and its new stock.
function recordStockIn(array $in): array {
    global $pdo;
    $product = getProduct((int)($in['product_id'] ?? 0));
    if (!$product || !$product['active']) throw new InventoryException('Choose an active product.');
    $qty = $in['quantity'] ?? '';
    if (!ctype_digit((string)$qty) || $qty < 1) throw new InventoryException('Enter a quantity of 1 or more.');
    $date = (string)($in['date'] ?? date('Y-m-d'));
    if (!validDate($date) || $date > date('Y-m-d')) throw new InventoryException('The date received must be a valid date, not in the future.');
    $exp = (string)($in['expiry_date'] ?? '');
    if ($exp !== '' && (!validDate($exp) || $exp < $date)) throw new InventoryException('Expiry cannot be before the date received.');
    $sid = (int)($in['supplier_id'] ?? 0);
    $supplierName = '';
    if ($sid) {
        $s = $pdo->prepare('SELECT supplier_name FROM suppliers WHERE supplier_id = ?');
        $s->execute([$sid]);
        $supplierName = $s->fetchColumn();
        if ($supplierName === false) throw new InventoryException('Unknown supplier.');
    }
    $when = $date === date('Y-m-d') ? date('Y-m-d H:i:s') : "$date 09:00:00";
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE products SET current_stock = current_stock + ? WHERE product_id = ?')->execute([$qty, $product['id']]);
    $pdo->prepare('INSERT INTO stock_in (product_id, quantity_received, expiry_date, supplier_id, user_id, received_at) VALUES (?,?,?,?,?,?)')
        ->execute([$product['id'], $qty, $exp ?: null, $sid ?: null, $_SESSION['user_id'], $when]);
    $pdo->commit();
    logAct("Recorded stock-in: $qty {$product['unit']} {$product['name']}" . ($supplierName ? " from $supplierName" : ''));
    $product['stock'] += $qty;
    return ['product' => $product, 'new_stock' => $product['stock']];
}

// Record usage / loss. A STAFF "Correction" is saved as pending until a manager or admin approves it.
function recordStockOut(array $in): array {
    global $pdo;
    $product = getProduct((int)($in['product_id'] ?? 0));
    if (!$product || !$product['active']) throw new InventoryException('Choose an active product.');
    $reason = (string)($in['reason'] ?? '');
    if (!isset(REASONS[$reason])) throw new InventoryException('Choose a reason: Sale, Spoilage, Waste or Correction.');
    $qty = $in['quantity'] ?? '';
    if (!ctype_digit((string)$qty) || $qty < 1) throw new InventoryException('Enter a quantity of 1 or more.');
    if ($qty > $product['stock']) {
        throw new InventoryException("Insufficient stock: only {$product['stock']} {$product['unit']} of {$product['name']} on hand. Enter {$product['stock']} or less.");
    }
    $note = trim((string)($in['note'] ?? ''));
    $pending = ($reason === 'Correction' && currentRole() === 'Staff');
    $pdo->beginTransaction();
    if (!$pending) {
        $pdo->prepare('UPDATE products SET current_stock = current_stock - ? WHERE product_id = ?')->execute([$qty, $product['id']]);
    }
    $pdo->prepare('INSERT INTO stock_out (product_id, quantity_used, reason, note, status, user_id) VALUES (?,?,?,?,?,?)')
        ->execute([$product['id'], $qty, $reason, $note, $pending ? 'pending' : 'approved', $_SESSION['user_id']]);
    $pdo->commit();
    if ($pending) {
        logAct("Submitted stock correction: $qty {$product['unit']} {$product['name']} (waiting for approval)");
        return ['product' => $product, 'pending' => true, 'warning' => ''];
    }
    logAct("Recorded stock-out: $qty {$product['unit']} {$product['name']} - $reason");
    return ['product' => $product, 'pending' => false, 'warning' => lowWarning($product['id'])];
}

/* ---------------- form actions (only when this file is the target of the form) ---------------- */
if (basename($_SERVER['SCRIPT_NAME']) === 'process.php' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    checkCsrf($_POST['csrf'] ?? '');
    $action = $_POST['action'];

    /* ----- login / logout ----- */
    if ($action === 'login') {
        if (attemptLogin($_POST['username'] ?? '', $_POST['password'] ?? '')) redirect('dashboard.php');
        redirect('login.php?error=1');
    }
    if ($action === 'logout') {
        logoutUser();
        redirect('login.php');
    }

    /* ----- products (Admin, Manager) ----- */
    if ($action === 'add_product' || $action === 'update_product') {
        requireRole('Admin', 'Manager');
        $id = $action === 'update_product' ? (int)($_POST['id'] ?? 0) : 0;
        try {
            saveProduct($_POST, $id);
        } catch (InventoryException $ex) {
            done('product_form.php' . ($id ? "?id=$id" : ''), $ex->getMessage(), 'err');
        }
        done('products.php', trim($_POST['name']) . ($id ? ' was updated.' : ' was added to the catalog.'));
    }
    if ($action === 'toggle_product') {
        requireRole('Admin', 'Manager');
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE product_id = ?')->execute([$id]);
        $p = getProduct($id);
        logAct(($p['active'] ? 'Reactivated' : 'Deactivated') . ' product ' . $p['name']);
        done('products.php', $p['name'] . ($p['active'] ? ' is active again.' : ' was removed from use. Its history is kept.'));
    }

    /* ----- categories (Admin, Manager) ----- */
    if ($action === 'add_category' || $action === 'rename_category') {
        requireRole('Admin', 'Manager');
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['category_name'] ?? '');
        if ($name === '' || strlen($name) > 50) done('categories.php', 'Enter a category name (50 characters or less).', 'err');
        $dup = $pdo->prepare('SELECT 1 FROM categories WHERE category_name = ? AND category_id <> ?');
        $dup->execute([$name, $id]);
        if ($dup->fetch()) done('categories.php', 'That category already exists.', 'err');
        if ($action === 'add_category') {
            $pdo->prepare('INSERT INTO categories (category_name) VALUES (?)')->execute([$name]);
            logAct("Added category $name");
            done('categories.php', "Category $name was added.");
        }
        $pdo->prepare('UPDATE categories SET category_name = ? WHERE category_id = ?')->execute([$name, $id]);
        logAct("Renamed category to $name");
        done('categories.php', "Category renamed to $name.");
    }
    if ($action === 'delete_category') {
        requireRole('Admin', 'Manager');
        $id = (int)($_POST['id'] ?? 0);
        $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM products WHERE category_id = ?) + (SELECT COUNT(*) FROM suppliers WHERE category_id = ?)');
        $used->execute([$id, $id]);
        if ($used->fetchColumn() > 0) done('categories.php', 'This category is still used by products or suppliers, so it cannot be deleted.', 'err');
        $pdo->prepare('DELETE FROM categories WHERE category_id = ?')->execute([$id]);
        logAct('Deleted an empty category');
        done('categories.php', 'Category deleted.');
    }

    /* ----- stock-in (Admin, Manager) ----- */
    if ($action === 'stock_in') {
        requireRole('Admin', 'Manager');
        try {
            $r = recordStockIn($_POST);
        } catch (InventoryException $ex) {
            done('stock_in.php', $ex->getMessage(), 'err');
        }
        done('stock_in.php', "Recorded {$_POST['quantity']} {$r['product']['unit']} of {$r['product']['name']}. Stock is now {$r['new_stock']}.");
    }

    /* ----- stock-out (everyone) ----- */
    if ($action === 'stock_out') {
        requireRole('Admin', 'Manager', 'Staff');
        try {
            $r = recordStockOut($_POST);
        } catch (InventoryException $ex) {
            done('stock_out.php', $ex->getMessage(), 'err');
        }
        if ($r['pending']) done('stock_out.php', 'Correction sent for approval. Stock changes once a manager or admin approves it.', 'warn');
        $p = $r['product'];
        done('stock_out.php', "Recorded {$_POST['quantity']} {$p['unit']} of {$p['name']} as " . strtolower($_POST['reason']) . '.' . $r['warning'], $r['warning'] ? 'warn' : 'ok');
    }

    if ($action === 'approve_correction' || $action === 'reject_correction') {
        requireRole('Admin', 'Manager');
        $id = (int)($_POST['id'] ?? 0);
        $s = $pdo->prepare("SELECT o.*, p.product_name, p.unit_of_measure unit, p.current_stock FROM stock_out o JOIN products p ON p.product_id = o.product_id
                            WHERE o.stock_out_id = ? AND o.status = 'pending'");
        $s->execute([$id]);
        $m = $s->fetch();
        if (!$m) done('stock_out.php', 'That correction was already handled.', 'err');

        if ($action === 'reject_correction') {
            $pdo->prepare("UPDATE stock_out SET status = 'rejected' WHERE stock_out_id = ?")->execute([$id]);
            logAct("Rejected stock correction: {$m['quantity_used']} {$m['unit']} {$m['product_name']}");
            done('stock_out.php', 'Correction rejected. Stock was not changed.');
        }
        if ($m['quantity_used'] > $m['current_stock']) {
            done('stock_out.php', "Only {$m['current_stock']} {$m['unit']} of {$m['product_name']} on hand. Reject this or fix the count first.", 'err');
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE products SET current_stock = current_stock - ? WHERE product_id = ?')->execute([$m['quantity_used'], $m['product_id']]);
        $pdo->prepare("UPDATE stock_out SET status = 'approved' WHERE stock_out_id = ?")->execute([$id]);
        $pdo->commit();
        logAct("Approved stock correction: {$m['quantity_used']} {$m['unit']} {$m['product_name']}");
        $warn = lowWarning((int)$m['product_id']);
        done('stock_out.php', 'Correction approved.' . $warn, $warn ? 'warn' : 'ok');
    }

    /* ----- suppliers (Admin, Manager) ----- */
    if ($action === 'add_supplier' || $action === 'update_supplier') {
        requireRole('Admin', 'Manager');
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['supplier_name'] ?? '');
        $catId  = (int)($_POST['category_id'] ?? 0);
        $person = trim($_POST['contact_person'] ?? '');
        $phone  = trim($_POST['contact_number'] ?? '');
        $back   = 'supplier_form.php' . ($id ? "?id=$id" : '');
        $c = $pdo->prepare('SELECT 1 FROM categories WHERE category_id = ?');
        $c->execute([$catId]);
        if ($name === '' || $person === '' || !$c->fetch() || !preg_match('/^[\d\s+()-]{7,20}$/', $phone)) {
            done($back, 'Fill in every field and enter a valid contact number.', 'err');
        }
        if ($action === 'add_supplier') {
            $pdo->prepare('INSERT INTO suppliers (supplier_name, category_id, contact_person, contact_number) VALUES (?,?,?,?)')
                ->execute([$name, $catId, $person, $phone]);
            logAct("Added supplier $name");
            done('suppliers.php', "$name was added.");
        }
        $pdo->prepare('UPDATE suppliers SET supplier_name=?, category_id=?, contact_person=?, contact_number=? WHERE supplier_id=?')
            ->execute([$name, $catId, $person, $phone, $id]);
        logAct("Updated supplier $name");
        done('suppliers.php', "$name was updated.");
    }

    /* ----- purchase orders ----- */
    // Manager raises an order (it then waits for the Admin). An order the Admin raises is approved straight away.
    if ($action === 'create_po') {
        requireRole('Admin', 'Manager');
        $sid  = (int)($_POST['supplier_id'] ?? 0);
        $eta  = (string)($_POST['expected_date'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $s = $pdo->prepare('SELECT supplier_name FROM suppliers WHERE supplier_id = ?');
        $s->execute([$sid]);
        $supplierName = $s->fetchColumn();
        if ($supplierName === false || !validDate($eta)) done('po_form.php', 'Choose a supplier and an expected delivery date.', 'err');

        // The form has several item rows; rows with no quantity are ignored
        $items = [];
        foreach ($_POST['product_id'] ?? [] as $i => $pid) {
            $qty = trim($_POST['qty'][$i] ?? '');
            if ($pid === '' || $qty === '') continue;
            $price = trim($_POST['price'][$i] ?? '');
            if (!ctype_digit($qty) || $qty < 1 || ($price !== '' && (!is_numeric($price) || $price < 0))) {
                done('po_form.php', 'Every item needs a whole-number quantity of 1 or more, and a valid price.', 'err');
            }
            if (isset($items[(int)$pid])) done('po_form.php', 'Each product can only appear once. Combine the quantities.', 'err');
            $items[(int)$pid] = ['qty' => (int)$qty, 'price' => $price];
        }
        if (!$items) done('po_form.php', 'Add at least one item.', 'err');

        $isAdmin = currentRole() === 'Admin';
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO purchase_orders (supplier_id, expected_date, note, created_by, approved_by, approved_at) VALUES (?,?,?,?,?,?)')
            ->execute([$sid, $eta, $note, $_SESSION['user_id'], $isAdmin ? $_SESSION['user_id'] : null, $isAdmin ? date('Y-m-d H:i:s') : null]);
        $poId = (int)$pdo->lastInsertId();
        $cost = $pdo->prepare('SELECT unit_cost FROM products WHERE product_id = ? AND is_active = 1');
        $ins  = $pdo->prepare('INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, unit_price) VALUES (?,?,?,?)');
        foreach ($items as $pid => $it) {
            $cost->execute([$pid]);
            $unitCost = $cost->fetchColumn();
            if ($unitCost === false) { $pdo->rollBack(); done('po_form.php', 'Unknown product in the list.', 'err'); }
            $ins->execute([$poId, $pid, $it['qty'], $it['price'] === '' ? $unitCost : $it['price']]);
        }
        $pdo->commit();
        logAct('Created ' . poCode($poId) . " for $supplierName");
        done("purchase_orders.php?id=$poId", poCode($poId) . ($isAdmin ? " created and approved for $supplierName." : " sent to the Admin for approval."));
    }

    if ($action === 'approve_po' || $action === 'reject_po') {
        requireRole('Admin');
        $id  = (int)($_POST['id'] ?? 0);
        $ret = ($_POST['return'] ?? '') === 'dashboard' ? 'dashboard.php' : "purchase_orders.php?id=$id";
        if ($action === 'approve_po') {
            $st = $pdo->prepare("UPDATE purchase_orders SET approved_by = ?, approved_at = NOW() WHERE po_id = ? AND status = 'Pending' AND approved_by IS NULL");
            $st->execute([$_SESSION['user_id'], $id]);
            if (!$st->rowCount()) done($ret, 'That order is not waiting for approval.', 'err');
            logAct('Approved ' . poCode($id));
            done($ret, poCode($id) . ' approved. It can now be received when it arrives.');
        }
        $st = $pdo->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE po_id = ? AND status = 'Pending' AND approved_by IS NULL");
        $st->execute([$id]);
        if (!$st->rowCount()) done($ret, 'That order is not waiting for approval.', 'err');
        logAct('Rejected ' . poCode($id));
        done($ret, poCode($id) . ' was rejected and cancelled.');
    }

    // Receiving an order marks it Delivered AND adds every line to stock. Only approved orders can be received.
    if ($action === 'receive_po') {
        requireRole('Admin', 'Manager');
        $id = (int)($_POST['id'] ?? 0);
        $s = $pdo->prepare("SELECT * FROM purchase_orders WHERE po_id = ? AND status = 'Pending'");
        $s->execute([$id]);
        $po = $s->fetch();
        if (!$po) done('purchase_orders.php', 'Only pending orders can be received.', 'err');
        if (!$po['approved_by']) done("purchase_orders.php?id=$id", 'This order must be approved by the Admin before it can be received.', 'err');

        $items = $pdo->prepare('SELECT product_id, quantity_ordered FROM purchase_order_items WHERE po_id = ?');
        $items->execute([$id]);
        $pdo->beginTransaction();
        foreach ($items->fetchAll() as $it) {
            $pdo->prepare('UPDATE products SET current_stock = current_stock + ? WHERE product_id = ?')->execute([$it['quantity_ordered'], $it['product_id']]);
            $pdo->prepare('INSERT INTO stock_in (product_id, quantity_received, supplier_id, po_id, user_id) VALUES (?,?,?,?,?)')
                ->execute([$it['product_id'], $it['quantity_ordered'], $po['supplier_id'], $id, $_SESSION['user_id']]);
        }
        $pdo->prepare("UPDATE purchase_orders SET status = 'Delivered', delivered_at = NOW() WHERE po_id = ?")->execute([$id]);
        $pdo->commit();
        logAct('Received ' . poCode($id) . ' into stock');
        done("purchase_orders.php?id=$id", poCode($id) . ' was received. Stock has been updated.');
    }

    // Admin can cancel any pending order; a Manager only before it has been approved
    if ($action === 'cancel_po') {
        requireRole('Admin', 'Manager');
        $id  = (int)($_POST['id'] ?? 0);
        $sql = "UPDATE purchase_orders SET status = 'Cancelled' WHERE po_id = ? AND status = 'Pending'" . (currentRole() === 'Admin' ? '' : ' AND approved_by IS NULL');
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        if (!$st->rowCount()) done("purchase_orders.php?id=$id", 'This order can no longer be cancelled by you.', 'err');
        logAct('Cancelled ' . poCode($id));
        done("purchase_orders.php?id=$id", poCode($id) . ' was cancelled.');
    }

    /* ----- users (Admin only: overall account control) ----- */
    if ($action === 'create_user') {
        requireRole('Admin');
        $name = trim($_POST['full_name'] ?? '');
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        if ($name === '' || !preg_match('/^[a-z0-9._]{3,50}$/i', $user) || strlen($pass) < 8 || !in_array($role, ['Admin', 'Manager', 'Staff'], true)) {
            done('users.php', 'Enter a name, a username (3+ letters or numbers), a password of 8+ characters, and a role.', 'err');
        }
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $chk->execute([$user]);
        if ($chk->fetch()) done('users.php', 'That username is taken.', 'err');
        $pdo->prepare('INSERT INTO users (full_name, username, password, role) VALUES (?,?,?,?)')
            ->execute([$name, strtolower($user), password_hash($pass, PASSWORD_DEFAULT), $role]);
        logAct("Added user $name ($role)");
        done('users.php', "$name can now log in.");
    }
    if ($action === 'reset_password') {
        requireRole('Admin');
        $id   = (int)($_POST['id'] ?? 0);
        $pass = $_POST['password'] ?? '';
        if (strlen($pass) < 8) done('users.php', 'The new password needs at least 8 characters.', 'err');
        $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        $name = $pdo->query("SELECT full_name FROM users WHERE user_id = $id")->fetchColumn();
        logAct("Reset password for $name");
        done('users.php', "Password reset for $name. Tell them the new one in person.");
    }
    if ($action === 'update_role') {
        requireRole('Admin');
        $id   = (int)($_POST['id'] ?? 0);
        $role = $_POST['role'] ?? '';
        if ($id === (int)$_SESSION['user_id']) done('users.php', 'You cannot change your own role.', 'err');
        if (!in_array($role, ['Admin', 'Manager', 'Staff'], true)) done('users.php', 'Choose a valid role.', 'err');
        $pdo->prepare('UPDATE users SET role = ? WHERE user_id = ?')->execute([$role, $id]);
        $name = $pdo->query("SELECT full_name FROM users WHERE user_id = $id")->fetchColumn();
        logAct("Changed role of $name to $role");
        done('users.php', "$name is now $role.");
    }
    if ($action === 'toggle_user') {
        requireRole('Admin');
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['user_id']) done('users.php', 'You cannot deactivate your own account.', 'err');
        $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'deactivated', 'active') WHERE user_id = ?")->execute([$id]);
        $u = $pdo->query("SELECT full_name, status FROM users WHERE user_id = $id")->fetch();
        logAct(($u['status'] === 'active' ? 'Reactivated' : 'Deactivated') . ' account for ' . $u['full_name']);
        done('users.php', $u['full_name'] . ($u['status'] === 'active' ? ' was reactivated.' : ' was deactivated.'));
    }
}
