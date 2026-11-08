<?php
require_once 'layout.php';
requireRole('Admin', 'Manager');

$supplier = null;
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $s = $pdo->prepare('SELECT * FROM suppliers WHERE supplier_id = ?');
    $s->execute([$id]);
    $supplier = $s->fetch();
    if (!$supplier) { http_response_code(404); exit('Supplier not found.'); }
}
$editing = $supplier !== null;
layoutTop($editing ? 'Edit supplier' : 'Add supplier', '', 'suppliers.php');
?>
<section class="panel form-card"><div class="panel-b" style="padding-top:20px">
    <form action="process.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update_supplier' : 'add_supplier' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$supplier['supplier_id'] ?>"><?php endif; ?>
        <div class="field"><label for="supplier_name">Supplier name</label><input id="supplier_name" name="supplier_name" required value="<?= e($supplier['supplier_name'] ?? '') ?>"></div>
        <div class="field"><label for="category_id">What they supply</label><select id="category_id" name="category_id"><?= categoryOptions($supplier['category_id'] ?? 0) ?></select></div>
        <div class="two">
            <div class="field"><label for="contact_person">Contact person</label><input id="contact_person" name="contact_person" required value="<?= e($supplier['contact_person'] ?? '') ?>"></div>
            <div class="field"><label for="contact_number">Contact number</label><input id="contact_number" name="contact_number" required placeholder="09XX XXX XXXX" value="<?= e($supplier['contact_number'] ?? '') ?>"></div>
        </div>
        <div class="form-actions">
            <a class="btn" href="suppliers.php">Cancel</a>
            <button class="btn primary" type="submit"><?= $editing ? 'Save changes' : 'Add supplier' ?></button>
        </div>
    </form>
</div></section>
<?php layoutBottom(); ?>
