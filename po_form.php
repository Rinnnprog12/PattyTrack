<?php
require_once 'layout.php';
requireRole('Admin', 'Manager');

$products  = $pdo->query(productSql('p.is_active = 1', 'c.category_name, p.product_name'))->fetchAll();
$suppliers = $pdo->query('SELECT s.*, c.category_name FROM suppliers s JOIN categories c ON c.category_id = s.category_id ORDER BY s.supplier_name')->fetchAll();


$preProduct = (int)($_GET['product'] ?? 0);
$preQty     = (int)($_GET['qty'] ?? 0);
$preSupplier = 0;
foreach ($products as $p) {
    if ($p['id'] == $preProduct) {
        foreach ($suppliers as $s) if ($s['category_id'] == $p['category_id']) { $preSupplier = $s['supplier_id']; break; }
    }
}
const ROWS = 5;
layoutTop('New purchase order', currentRole() === 'Admin' ? 'Orders you create are approved automatically' : 'Orders you create are sent to the Admin for approval', 'purchase_orders.php');
?>
<section class="panel form-card" style="max-width:720px"><div class="panel-b" style="padding-top:20px">
    <form action="process.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_po">
        <div class="two">
            <div class="field"><label for="supplier_id">Supplier</label>
                <select id="supplier_id" name="supplier_id" required>
                    <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['supplier_id'] ?>" <?= $s['supplier_id'] == $preSupplier ? 'selected' : '' ?>><?= e($s['supplier_name']) ?> (<?= e($s['category_name']) ?>)</option><?php endforeach; ?>
                </select></div>
            <div class="field"><label for="expected_date">Expected delivery</label><input id="expected_date" type="date" name="expected_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" min="<?= date('Y-m-d') ?>" required></div>
        </div>
        <div class="field">
            <span class="lbl">Items</span>
            <div class="line-h"><span>Product</span><span>Qty</span><span>Unit ₱</span></div>
            <?php for ($r = 0; $r < ROWS; $r++): ?>
                <div class="line">
                    <select name="product_id[]" aria-label="Product">
                        <option value="">— none —</option>
                        <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= ($r === 0 && $p['id'] == $preProduct) ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['category']) ?>)</option><?php endforeach; ?>
                    </select>
                    <input type="number" name="qty[]" min="1" step="1" placeholder="Qty" aria-label="Quantity" value="<?= ($r === 0 && $preQty) ? $preQty : '' ?>">
                    <input type="number" name="price[]" min="0" step="0.01" placeholder="auto" aria-label="Unit price">
                </div>
            <?php endfor; ?>
            <span class="hint">Leave unused rows empty. Leave the price empty to use the product's unit cost.</span>
        </div>
        <div class="field"><label for="note">Note</label><input id="note" name="note" maxlength="120" placeholder="Optional. e.g. Deliver before 8 AM"></div>
        <div class="form-actions">
            <a class="btn" href="purchase_orders.php">Cancel</a>
            <button class="btn primary" type="submit">Create order</button>
        </div>
    </form>
</div></section>
<?php layoutBottom(); ?>
