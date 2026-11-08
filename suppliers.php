<?php
require_once 'layout.php';
requireRole('Admin', 'Manager');

$suppliers = $pdo->query("SELECT s.*, c.category_name,
        (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.supplier_id AND status = 'Pending') open_orders,
        (SELECT MAX(delivered_at) FROM purchase_orders WHERE supplier_id = s.supplier_id AND status = 'Delivered') last_delivery
        FROM suppliers s JOIN categories c ON c.category_id = s.category_id ORDER BY s.supplier_name")->fetchAll();
layoutTop('Suppliers', 'Who you buy from');
?>
<div class="page-head"><p>Contact details for the people you buy from.</p>
    <a class="btn primary" href="supplier_form.php"><?= icon('plus') ?>Add supplier</a></div>
<section class="panel"><div class="tbl-wrap"><table>
    <thead><tr><th>Supplier</th><th>Supplies</th><th>Contact</th><th class="num">Open orders</th><th>Last delivery</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
        <tr>
            <td><span class="name"><?= e($s['supplier_name']) ?></span><small><?= e($s['contact_person']) ?></small></td>
            <td><?= e($s['category_name']) ?></td>
            <td><?= e($s['contact_number']) ?></td>
            <td class="num"><?= $s['open_orders'] ?: '—' ?></td>
            <td><?= $s['last_delivery'] ? date('M j, Y', strtotime($s['last_delivery'])) : '—' ?></td>
            <td><div class="acts">
                <a class="btn ghost sm" href="purchase_orders.php?supplier=<?= (int)$s['supplier_id'] ?>">Orders</a>
                <a class="btn ghost sm" href="supplier_form.php?id=<?= (int)$s['supplier_id'] ?>"><?= icon('edit') ?>Edit</a>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></section>
<?php layoutBottom(); ?>
