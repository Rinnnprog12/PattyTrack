<?php
require_once 'layout.php';
requireRole('Admin', 'Manager');

$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.category_id) products,
                            (SELECT COUNT(*) FROM suppliers WHERE category_id = c.category_id) suppliers
                     FROM categories c ORDER BY c.category_name')->fetchAll();
layoutTop('Categories', 'Group items so they are easy to find');
?>
<div class="grid g-form">
    <section class="panel">
        <div class="panel-h"><h2>Add category</h2></div>
        <div class="panel-b">
            <form action="process.php" method="post">
                <?= csrfField() ?><input type="hidden" name="action" value="add_category">
                <div class="field"><label for="category_name">Category name</label><input id="category_name" name="category_name" required maxlength="50" placeholder="e.g. Beverages"></div>
                <button class="btn primary" type="submit"><?= icon('plus') ?>Add category</button>
            </form>
        </div>
    </section>
    <section class="panel">
        <div class="panel-h"><h2>All categories</h2><span class="meta"><?= count($cats) ?> total</span></div>
        <div class="tbl-wrap" style="margin-top:10px"><table>
            <thead><tr><th>Name</th><th class="num">Products</th><th class="num">Suppliers</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cats as $c): ?>
                <tr>
                    <td>
                        <form class="pw-reset" action="process.php" method="post">
                            <?= csrfField() ?><input type="hidden" name="action" value="rename_category"><input type="hidden" name="id" value="<?= (int)$c['category_id'] ?>">
                            <input type="text" name="category_name" value="<?= e($c['category_name']) ?>" maxlength="50" required aria-label="Category name" style="width:190px">
                            <button class="btn sm" type="submit">Rename</button>
                        </form>
                    </td>
                    <td class="num"><?= (int)$c['products'] ?></td>
                    <td class="num"><?= (int)$c['suppliers'] ?></td>
                    <td><div class="acts">
                        <?php if (!$c['products'] && !$c['suppliers']): ?>
                        <form action="process.php" method="post" onsubmit="return confirm('Delete this category?');">
                            <?= csrfField() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= (int)$c['category_id'] ?>">
                            <button class="btn ghost sm danger" type="submit">Delete</button>
                        </form>
                        <?php else: ?><span class="hint">In use</span><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layoutBottom(); ?>
