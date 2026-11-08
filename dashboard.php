<?php
require_once 'layout.php';
requireRole('Admin', 'Manager', 'Staff');
$role = currentRole();

$low     = lowProducts();
$activeN = $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
$inToday = $pdo->query('SELECT COUNT(*) FROM stock_in WHERE DATE(received_at) = CURDATE()')->fetchColumn();
$usedToday = $pdo->query("SELECT COUNT(*) FROM stock_out WHERE status <> 'rejected' AND DATE(used_at) = CURDATE()")->fetchColumn();
$invValue  = $pdo->query('SELECT COALESCE(SUM(current_stock * unit_cost), 0) FROM products WHERE is_active = 1')->fetchColumn();
$awaiting  = awaitingPoCount();
$corrections = pendingCorrectionCount();

// Open orders: awaiting approval first, then approved ones waiting for delivery
$openPos = $pdo->query("SELECT po.*, s.supplier_name supplier,
                        (SELECT SUM(quantity_ordered * unit_price) FROM purchase_order_items WHERE po_id = po.po_id) total,
                        (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.po_id) n
                        FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id
                        WHERE po.status = 'Pending' ORDER BY po.approved_by IS NOT NULL, po.created_at DESC")->fetchAll();
$mine = $pdo->prepare('SELECT (SELECT COUNT(*) FROM stock_out WHERE user_id = ? AND DATE(used_at) = CURDATE())
                            + (SELECT COUNT(*) FROM stock_in WHERE user_id = ? AND DATE(received_at) = CURDATE())');
$mine->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$mineN = $mine->fetchColumn();

$acts = $pdo->query("SELECT a.*, u.full_name name FROM activity_logs a JOIN users u ON u.user_id = a.user_id
                     WHERE a.action_taken <> 'Logged in' ORDER BY a.created_at DESC, a.log_id DESC LIMIT 7")->fetchAll();
$week = dailySeries(date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));

$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
layoutTop($greet . ', ' . explode(' ', $_SESSION['name'])[0], date('M j, Y') . " · Angel's Burger, Tarlac Branch");
?>
<div class="page-head">
    <p><?= $role === 'Admin' ? 'Everything happening at the branch, and what needs your approval.' : "Live view of what's on hand at the counter." ?></p>
    <div class="row-actions">
        <?php if ($role !== 'Staff'): ?><a class="btn primary" href="stock_in.php"><?= icon('in') ?>Record delivery</a><?php endif; ?>
        <a class="btn <?= $role === 'Staff' ? 'primary' : '' ?>" href="stock_out.php"><?= icon('out') ?>Record usage</a>
    </div>
</div>

<?php if ($role !== 'Staff' && $corrections): ?>
    <div class="panel" style="padding:14px 18px;margin-bottom:18px;border-color:var(--gold)">
        <b><?= $corrections ?> stock correction<?= $corrections > 1 ? 's' : '' ?> waiting for approval.</b>
        <a class="link" href="stock_out.php">Review now</a>
    </div>
<?php endif; ?>

<section class="kpis">
    <a class="kpi" href="products.php"><span class="kpi-v"><?= $activeN ?></span><span class="kpi-k">Total items</span></a>
    <a class="kpi <?= $low ? 'red' : '' ?>" href="alerts.php"><span class="kpi-v"><?= count($low) ?></span><span class="kpi-k">Low-stock items</span></a>
    <?php if ($role === 'Admin'): ?>
        <a class="kpi <?= $awaiting ? 'red' : '' ?>" href="purchase_orders.php?status=awaiting"><span class="kpi-v"><?= $awaiting ?></span><span class="kpi-k">Orders awaiting approval</span></a>
        <a class="kpi" href="reports.php"><span class="kpi-v"><?= peso($invValue, 0) ?></span><span class="kpi-k">Inventory value</span></a>
    <?php elseif ($role === 'Manager'): ?>
        <div class="kpi"><span class="kpi-v"><?= $inToday ?></span><span class="kpi-k">Deliveries today</span></div>
        <a class="kpi" href="purchase_orders.php"><span class="kpi-v"><?= count($openPos) ?></span><span class="kpi-k">Open purchase orders</span></a>
    <?php else: ?>
        <div class="kpi"><span class="kpi-v"><?= $usedToday ?></span><span class="kpi-k">Usage entries today</span></div>
        <a class="kpi" href="activity.php"><span class="kpi-v"><?= $mineN ?></span><span class="kpi-k">Your entries today</span></a>
    <?php endif; ?>
</section>

<div class="grid g-2-1">
    <section class="panel">
        <div class="panel-h"><h2>Needs restocking</h2><?php if ($low): ?><a class="link" href="alerts.php">See all <?= count($low) ?></a><?php endif; ?></div>
        <div class="panel-b">
            <?php if (!$low): ?>
                <div class="empty"><span class="ring"><?= icon('check') ?></span><b>All stocked up</b><span>Nothing is at or below its reorder level.</span></div>
            <?php endif; ?>
            <?php foreach (array_slice($low, 0, 5) as $p): ?>
                <div class="alert-row">
                    <div><b><?= e($p['name']) ?></b><small><?= $p['stock'] == 0 ? 'Out of stock' : e($p['stock'] . ' ' . $p['unit'] . ' left') ?> · reorder at <?= (int)$p['reorder_level'] ?></small></div>
                    <?= meter($p) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="panel">
        <div class="panel-h"><h2>Stock movement, last 7 days</h2>
            <div class="legend"><span><i style="background:#7FDCA0"></i>In</span><span><i style="background:#FFC43D"></i>Out</span></div></div>
        <div class="panel-b"><div class="chart-box"><canvas id="chWeek"></canvas></div></div>
    </section>
</div>

<div class="grid <?= $role === 'Staff' ? '' : 'g-7-5' ?> mt">
    <section class="panel">
        <div class="panel-h"><h2>Recent activity</h2><a class="link" href="activity.php"><?= $role === 'Staff' ? 'My activity' : 'Full log' ?></a></div>
        <div class="panel-b"><ul class="feed">
            <?php foreach ($acts as $a): ?>
                <li><span class="avatar"><?= e(initials($a['name'])) ?></span>
                    <div><p><?= e($a['action_taken']) ?></p><small><?= e($a['name']) ?> · <?= ago($a['created_at']) ?></small></div></li>
            <?php endforeach; ?>
        </ul></div>
    </section>
    <?php if ($role !== 'Staff'): ?>
    <section class="panel">
        <div class="panel-h"><h2><?= $role === 'Admin' ? 'Purchase orders to review' : 'Open purchase orders' ?></h2><a class="link" href="purchase_orders.php">View all</a></div>
        <div class="panel-b">
            <?php if (!$openPos): ?><div class="empty"><b>No open orders</b><span>Orders sent to suppliers show up here until they arrive.</span></div><?php endif; ?>
            <?php foreach ($openPos as $po): ?>
                <div class="po-mini">
                    <div><b><a class="link" style="color:var(--text)" href="purchase_orders.php?id=<?= (int)$po['po_id'] ?>"><?= poCode($po['po_id']) ?></a></b> <?= poBadge($po) ?>
                        <small><?= e($po['supplier']) ?> · <?= (int)$po['n'] ?> item<?= $po['n'] > 1 ? 's' : '' ?> · <?= peso($po['total'], 0) ?></small></div>
                    <?php if ($role === 'Admin' && !$po['approved_by']): ?>
                    <div class="acts">
                        <form action="process.php" method="post"><?= csrfField() ?><input type="hidden" name="action" value="approve_po"><input type="hidden" name="id" value="<?= (int)$po['po_id'] ?>"><input type="hidden" name="return" value="dashboard"><button class="btn sm gold" type="submit">Approve</button></form>
                        <form action="process.php" method="post" onsubmit="return confirm('Reject and cancel this order?');"><?= csrfField() ?><input type="hidden" name="action" value="reject_po"><input type="hidden" name="id" value="<?= (int)$po['po_id'] ?>"><input type="hidden" name="return" value="dashboard"><button class="btn sm ghost danger" type="submit">Reject</button></form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php chartScript(); ?>
<script>movementChart('chWeek', <?= json_encode($week) ?>);</script>
<?php layoutBottom(); ?>
