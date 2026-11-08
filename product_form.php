<?php
require_once 'layout.php';
requireRole('Admin', 'Manager');

$id = (int)($_GET['id'] ?? 0);
$product = null;
if ($id > 0) {
    $product = getProduct($id);
    if (!$product) { http_response_code(404); exit('Product not found.'); }
}
$editing = $product !== null;
layoutTop($editing ? 'Edit product' : 'Add product', '', 'products.php');
?>
<section class="panel form-card"><div class="panel-b" style="padding-top:20px">
    <form action="process.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update_product' : 'add_product' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><?php endif; ?>
        <div class="field"><label for="name">Product name</label><input id="name" name="name" required value="<?= e($product['name'] ?? '') ?>" placeholder="e.g. Beef Patty (Regular)"></div>
        <div class="two">
            <div class="field"><label for="category_id">Category</label><select id="category_id" name="category_id"><?= categoryOptions($product['category_id'] ?? 0) ?></select>
                <span class="hint"><a class="link" href="categories.php">Manage categories</a></span></div>
            <div class="field"><label for="unit">Unit of measure</label><input id="unit" name="unit" required value="<?= e($product['unit'] ?? '') ?>" placeholder="pcs, pack, tub"></div>
        </div>
        <div class="two">
            <div class="field"><label for="reorder_level">Reorder level</label><input id="reorder_level" type="number" min="0" step="1" name="reorder_level" required value="<?= e($product['reorder_level'] ?? '') ?>"><span class="hint">Alert appears at or below this amount.</span></div>
            <div class="field"><label for="unit_cost">Unit cost (₱)</label><input id="unit_cost" type="number" min="0" step="0.01" name="unit_cost" required value="<?= e($product['unit_cost'] ?? '') ?>"></div>
        </div>
        <?php if (!$editing): ?>
        <div class="field"><label for="stock">Starting stock</label><input id="stock" type="number" min="0" step="1" name="stock" value="0" required></div>
        <?php else: ?>
        <p class="hint" style="margin-bottom:16px">On hand: <b><?= (int)$product['stock'] ?> <?= e($product['unit']) ?></b>. Stock changes only through Stock-in and Stock-out so the history stays accurate.</p>
        <?php endif; ?>
        <div class="form-actions">
            <a class="btn" href="products.php">Cancel</a>
            <button class="btn primary" type="submit"><?= $editing ? 'Save changes' : 'Add product' ?></button>
        </div>
    </form>
</div></section>
<?php layoutBottom(); ?>
