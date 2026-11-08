<?php
require_once 'layout.php';
requireRole('Admin', 'Manager');
$isAdmin = currentRole() === 'Admin';

$filter   = $_GET['status'] ?? 'all';
$supplier = (int)($_GET['supplier'] ?? 0);
$sql  = "SELECT po.*, s.supplier_name supplier, cu.full_name creator, au.full_name approver,
                (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.po_id) n,
                (SELECT SUM(quantity_ordered * unit_price) FROM purchase_order_items WHERE po_id = po.po_id) total
         FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id
         JOIN users cu ON cu.user_id = po.created_by LEFT JOIN users au ON au.user_id = po.approved_by WHERE 1=1";
$args = [];
if ($filter === 'awaiting')  $sql .= " AND po.status = 'Pending' AND po.approved_by IS NULL";
if ($filter === 'approved')  $sql .= " AND po.status = 'Pending' AND po.approved_by IS NOT NULL";
if ($filter === 'Delivered' || $filter === 'Cancelled') { $sql .= ' AND po.status = ?'; $args[] = $filter; }
if ($supplier) { $sql .= ' AND po.supplier_id = ?'; $args[] = $supplier; }
$stmt = $pdo->prepare($sql . ' ORDER BY po.created_at DESC, po.po_id DESC');
$stmt->execute($args);
$orders = $stmt->fetchAll();

$counts = ['all' => $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn(),
           'awaiting' => awaitingPoCount(),
           'approved' => $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending' AND approved_by IS NOT NULL")->fetchColumn(),
           'Delivered' => $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Delivered'")->fetchColumn(),
           'Cancelled' => $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Cancelled'")->fetchColumn()];

$selId = (int)($_GET['id'] ?? ($orders[0]['po_id'] ?? 0));
$sel = null;
foreach ($orders as $o) if ($o['po_id'] == $selId) $sel = $o;
$items = [];
if ($sel) {
    $it = $pdo->prepare('SELECT i.*, p.product_name, p.unit_of_measure unit FROM purchase_order_items i JOIN products p ON p.product_id = i.product_id WHERE i.po_id = ?');
    $it->execute([$selId]);
    $items = $it->fetchAll();
}
layoutTop('Purchase orders', $isAdmin ? 'Approve and track supplier orders' : 'Create and track supplier orders');
?>
<div class="page-head">
    <div class="seg">
        <?php foreach (['all' => 'All', 'awaiting' => 'Awaiting approval', 'approved' => 'Approved', 'Delivered' => 'Delivered', 'Cancelled' => 'Cancelled'] as $k => $label): ?>
            <a href="purchase_orders.php?status=<?= $k ?><?= $supplier ? "&supplier=$supplier" : '' ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $label ?><span class="n"><?= $counts[$k] ?></span></a>
        <?php endforeach; ?>
    </div>
    <a class="btn primary" href="po_form.php"><?= icon('plus') ?>New purchase order</a>
</div>
<?php if (!$isAdmin): ?><p class="hint" style="margin:-8px 0 16px">Orders you create are sent to the Admin for approval before they can be received.</p><?php endif; ?>
<section class="panel">
<?php if (!$orders): ?>
    <div class="empty"><b>No orders here</b><span>Nothing matches this filter.</span></div>
<?php else: ?>
    <div class="tbl-wrap"><table>
        <thead><tr><th>Order</th><th>Supplier</th><th>Created</th><th class="num">Items</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr class="<?= $o['po_id'] == $selId ? 'sel' : '' ?>">
                <td><b><?= poCode($o['po_id']) ?></b></td>
                <td><?= e($o['supplier']) ?></td>
                <td class="nw"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                <td class="num"><?= (int)$o['n'] ?></td>
                <td class="num"><?= peso($o['total']) ?></td>
                <td><?= poBadge($o) ?></td>
                <td class="num"><a class="link" href="purchase_orders.php?status=<?= e($filter) ?>&id=<?= (int)$o['po_id'] ?><?= $supplier ? "&supplier=$supplier" : '' ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</section>

<?php if ($sel):
    $awaiting = $sel['status'] === 'Pending' && !$sel['approved_by'];
    $approved = $sel['status'] === 'Pending' && $sel['approved_by'];
    $btn = fn(string $action, string $label, string $class, string $confirm = '') =>
        '<form action="process.php" method="post"' . ($confirm ? ' onsubmit="return confirm(\'' . $confirm . '\');"' : '') . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . (int)$sel['po_id'] . '">'
        . '<button class="btn sm ' . $class . '" type="submit">' . $label . '</button></form>';
?>
<section class="panel mt">
    <div class="po-head">
        <div><h2><?= poCode($sel['po_id']) ?> · <?= e($sel['supplier']) ?></h2>
            <small>Created by <?= e($sel['creator']) ?> on <?= date('M j, Y', strtotime($sel['created_at'])) ?>
                <?= $sel['approver'] ? ' · Approved by ' . e($sel['approver']) . ' on ' . date('M j', strtotime($sel['approved_at'])) : '' ?>
                · Expected <?= date('M j, Y', strtotime($sel['expected_date'])) ?>
                <?= $sel['delivered_at'] ? ' · Delivered ' . date('M j, Y', strtotime($sel['delivered_at'])) : '' ?></small></div>
        <div class="row-actions">
            <?= poBadge($sel) ?>
            <?php if ($awaiting && $isAdmin): ?>
                <?= $btn('reject_po', 'Reject', 'danger', 'Reject and cancel this order?') ?>
                <?= $btn('approve_po', icon('check') . 'Approve order', 'gold') ?>
            <?php elseif ($awaiting): ?>
                <?= $btn('cancel_po', 'Cancel order', 'danger', 'Cancel this order?') ?>
            <?php elseif ($approved): ?>
                <?php if ($isAdmin) echo $btn('cancel_po', 'Cancel order', 'danger', 'Cancel this approved order?'); ?>
                <?= $btn('receive_po', icon('check') . 'Receive delivery', 'gold', 'Mark as delivered and add these items to stock?') ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="tbl-wrap"><table>
        <thead><tr><th>Product</th><th class="num">Quantity</th><th class="num">Unit price</th><th class="num">Subtotal</th></tr></thead>
        <tbody>
        <?php foreach ($items as $i): ?>
            <tr><td><span class="name"><?= e($i['product_name']) ?></span></td><td class="num"><?= (int)$i['quantity_ordered'] ?> <?= e($i['unit']) ?></td><td class="num"><?= peso($i['unit_price']) ?></td><td class="num"><?= peso($i['quantity_ordered'] * $i['unit_price']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="tfoot"><span><?= $sel['note'] ? e($sel['note']) : 'No notes.' ?><?= $awaiting && !$isAdmin ? ' · Waiting for the Admin to approve.' : '' ?></span><b style="color:var(--text);font-size:15px">Total <?= peso($sel['total']) ?></b></div>
</section>
<?php endif; ?>
<?php layoutBottom(); ?>
