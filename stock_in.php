<?php
require_once 'layout.php';
requireRole('Admin', 'Manager');

$products  = $pdo->query(productSql('p.is_active = 1'))->fetchAll();
$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY supplier_name')->fetchAll();
$recent    = $pdo->query("SELECT s.*, p.product_name, p.unit_of_measure unit, sp.supplier_name, u.full_name
                          FROM stock_in s JOIN products p ON p.product_id = s.product_id JOIN users u ON u.user_id = s.user_id
                          LEFT JOIN suppliers sp ON sp.supplier_id = s.supplier_id
                          ORDER BY s.received_at DESC, s.stock_in_id DESC LIMIT 9")->fetchAll();
$today = $pdo->query('SELECT COUNT(*) FROM stock_in WHERE DATE(received_at) = CURDATE()')->fetchColumn();
layoutTop('Stock-in', 'Receive supplier deliveries');
?>
<div class="grid g-form">
    <section class="panel">
        <div class="panel-h"><h2>Record a delivery</h2></div>
        <div class="panel-b">
            <form action="process.php" method="post">
                <?= csrfField() ?><input type="hidden" name="action" value="stock_in">
                <div class="field"><label for="product_id">Product</label>
                    <select id="product_id" name="product_id" required>
                        <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= (int)$p['stock'] ?> <?= e($p['unit']) ?> on hand)</option><?php endforeach; ?>
                    </select></div>
                <div class="field"><label for="supplier_id">Supplier</label>
                    <select id="supplier_id" name="supplier_id">
                        <option value="">Not listed</option>
                        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['supplier_id'] ?>"><?= e($s['supplier_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="two">
                    <div class="field"><label for="quantity">Quantity received</label><input id="quantity" type="number" name="quantity" min="1" step="1" required placeholder="0"></div>
                    <div class="field"><label for="date">Date received</label><input id="date" type="date" name="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                </div>
                <div class="field"><label for="expiry_date">Expiry date</label><input id="expiry_date" type="date" name="expiry_date" min="<?= date('Y-m-d') ?>"><span class="hint">Optional, for reference only. Add it for patties, cheese and other perishables.</span></div>
                <button class="btn primary" type="submit" style="width:100%;height:42px"><?= icon('check') ?>Record delivery</button>
            </form>
            <p class="hint" style="margin-top:12px">Receiving a whole purchase order? Open it in Purchase orders and use "Receive delivery".</p>
        </div>
    </section>
    <section class="panel">
        <div class="panel-h"><h2>Recent deliveries</h2><span class="meta"><?= $today ?> logged today</span></div>
        <div class="tbl-wrap" style="margin-top:10px"><table>
            <thead><tr><th>When</th><th>Product</th><th class="num">Qty</th><th>Supplier</th><th>Expiry</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="nw"><?= e(when($r['received_at'])) ?></td>
                    <td><span class="name"><?= e($r['product_name']) ?></span><?php if ($r['po_id']): ?><small><?= poCode($r['po_id']) ?></small><?php endif; ?></td>
                    <td class="num"><b>+<?= (int)$r['quantity_received'] ?></b> <span style="color:var(--muted)"><?= e($r['unit']) ?></span></td>
                    <td><?= e($r['supplier_name'] ?? '—') ?></td>
                    <td class="nw"><?= $r['expiry_date'] ? date('M j, Y', strtotime($r['expiry_date'])) : '—' ?></td>
                    <td><?= e(explode(' ', $r['full_name'])[0]) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layoutBottom(); ?>
