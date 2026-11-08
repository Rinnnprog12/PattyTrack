<?php
require_once 'layout.php';
requireRole('Admin', 'Manager', 'Staff');
$canEdit = in_array(currentRole(), ['Admin', 'Manager'], true);

// Search, filter and sort come from the URL so the page works without JavaScript
$q      = trim($_GET['q'] ?? '');
$cat    = (int)($_GET['cat'] ?? 0);
$status = $_GET['status'] ?? 'all';
$sorts  = ['name' => 'p.product_name', 'category' => 'c.category_name', 'stock' => 'p.current_stock', 'updated' => 'p.updated_at'];
$sort   = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'name';
$dir    = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$where = '1=1';
$args = [];
if ($q !== '')  { $where .= ' AND (p.product_name LIKE ? OR c.category_name LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
if ($cat)       { $where .= ' AND p.category_id = ?'; $args[] = $cat; }
if ($status === 'low') $where .= ' AND p.is_active = 1 AND p.current_stock <= p.reorder_level';
if ($status === 'ok')  $where .= ' AND p.is_active = 1 AND p.current_stock > p.reorder_level';
if ($status === 'off') $where .= ' AND p.is_active = 0';
$stmt = $pdo->prepare(productSql($where, $sorts[$sort] . ' ' . $dir));
$stmt->execute($args);
$products = $stmt->fetchAll();

function listUrl(array $change): string {
    return 'products.php?' . http_build_query(array_merge(['q' => $_GET['q'] ?? '', 'cat' => $_GET['cat'] ?? '', 'status' => $_GET['status'] ?? 'all',
                                                           'sort' => $_GET['sort'] ?? 'name', 'dir' => $_GET['dir'] ?? 'asc'], $change));
}
function sortLink(string $key, string $label, string $current, string $dir): string {
    $next = ($current === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $current === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a class="sortlink" href="' . e(listUrl(['sort' => $key, 'dir' => $next])) . '">' . $label . $arrow . '</a>';
}
layoutTop('Products', 'Inventory catalog and stock levels');
?>
<div class="page-head">
    <p><?= $canEdit ? 'Add, edit and remove the items you stock.' : 'Check what is available before you start your shift.' ?></p>
    <?php if ($canEdit): ?><a class="btn primary" href="product_form.php"><?= icon('plus') ?>Add product</a><?php endif; ?>
</div>
<section class="panel">
    <form class="toolbar" method="get">
        <div class="search"><?= icon('search') ?><input type="search" name="q" placeholder="Search by name or category" value="<?= e($q) ?>" aria-label="Search products"></div>
        <select name="cat" style="width:auto;min-width:170px" aria-label="Category" onchange="this.form.submit()">
            <?= categoryOptions($cat, 'All categories') ?>
        </select>
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
        <div class="seg">
            <?php foreach (['all' => 'All', 'low' => 'Low', 'ok' => 'OK', 'off' => 'Removed'] as $k => $label): ?>
                <a href="<?= e(listUrl(['status' => $k])) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </form>
    <?php if (!$products): ?>
        <div class="empty"><b>No products match</b><span>Try a different search or clear the filters.</span><a class="btn sm" href="products.php">Clear filters</a></div>
    <?php else: ?>
    <div class="tbl-wrap"><table>
        <thead><tr>
            <th><?= sortLink('name', 'Product', $sort, $dir) ?></th><th><?= sortLink('category', 'Category', $sort, $dir) ?></th>
            <th class="num"><?= sortLink('stock', 'On hand', $sort, $dir) ?></th><th style="min-width:130px">Level</th>
            <th class="num">Reorder at</th><th class="num">Unit cost</th><th><?= sortLink('updated', 'Updated', $sort, $dir) ?></th><th>Status</th>
            <?php if ($canEdit): ?><th></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><span class="name"><?= e($p['name']) ?></span></td>
                <td class="nw"><?= e($p['category']) ?></td>
                <td class="num"><b><?= (int)$p['stock'] ?></b> <span style="color:var(--muted)"><?= e($p['unit']) ?></span></td>
                <td><?= meter($p) ?></td>
                <td class="num"><?= (int)$p['reorder_level'] ?></td>
                <td class="num"><?= peso($p['unit_cost']) ?></td>
                <td class="nw" style="color:var(--muted)"><?= date('M j', strtotime($p['updated_at'])) ?></td>
                <td><?= stockBadge($p) ?></td>
                <?php if ($canEdit): ?>
                <td><div class="acts">
                    <a class="btn ghost sm" href="product_form.php?id=<?= (int)$p['id'] ?>"><?= icon('edit') ?>Edit</a>
                    <form action="process.php" method="post" onsubmit="return confirm('<?= $p['active'] ? 'Remove this product from use? Its history is kept.' : 'Reactivate this product?' ?>');">
                        <?= csrfField() ?><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn ghost sm <?= $p['active'] ? 'danger' : '' ?>" type="submit"><?= $p['active'] ? 'Remove' : 'Reactivate' ?></button>
                    </form>
                </div></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="tfoot"><span><?= count($products) ?> product<?= count($products) == 1 ? '' : 's' ?></span><span>The white mark on each bar is the reorder level.</span></div>
    <?php endif; ?>
</section>
<?php layoutBottom(); ?>
