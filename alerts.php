<?php
require_once 'layout.php';
requireRole('Admin', 'Manager', 'Staff');
$canOrder = in_array(currentRole(), ['Admin', 'Manager'], true);

$low = lowProducts();
$ordered = 0;
foreach ($low as $i => $p) {
    $low[$i]['orders'] = onOrder((int)$p['id']);
    if ($low[$i]['orders']) $ordered++;
}
layoutTop('Low-stock alerts', 'Items at or below their reorder level');
?>
<div class="page-head"><p>
    <?php if ($low): ?><?= count($low) ?> item<?= count($low) > 1 ? 's are' : ' is' ?> at or below the reorder level<?= $ordered ? ", $ordered already on order" : '' ?>.
    <?php else: ?>Nothing needs reordering right now.<?php endif; ?>
</p></div>
<section class="panel">
<?php if (!$low): ?>
    <div class="empty"><span class="ring"><?= icon('check') ?></span><b>All stocked up</b><span>Every active product is above its reorder level.</span></div>
<?php else: ?>
    <div class="tbl-wrap"><table>
        <thead><tr><th>Product</th><th class="num">On hand</th><th style="min-width:140px">Level</th><th class="num">Reorder at</th><th>Order status</th><?php if ($canOrder): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($low as $p): ?>
            <tr>
                <td><span class="name"><?= e($p['name']) ?></span><small><?= e($p['category']) ?></small></td>
                <td class="num"><?= $p['stock'] == 0 ? '<span class="badge out">Out</span>' : '<b>' . (int)$p['stock'] . '</b> <span style="color:var(--muted)">' . e($p['unit']) . '</span>' ?></td>
                <td><?= meter($p) ?></td>
                <td class="num"><?= (int)$p['reorder_level'] ?></td>
                <td>
                    <?php if ($p['orders']): foreach ($p['orders'] as $o): ?>
                        <span class="badge pending">On order</span> <small style="display:inline"><?= poCode($o['id']) ?> · <?= (int)$o['quantity'] ?> <?= e($p['unit']) ?></small><br>
                    <?php endforeach; else: ?><span class="badge low">Not ordered</span><?php endif; ?>
                </td>
                <?php if ($canOrder): ?>
                <td class="num"><?php if (!$p['orders']): ?>
                    <?php $suggest = max((int)(ceil(($p['reorder_level'] * 2 - $p['stock']) / 10) * 10), 10); ?>
                    <a class="btn sm primary" href="po_form.php?product=<?= (int)$p['id'] ?>&qty=<?= $suggest ?>"><?= icon('plus') ?>Create order</a>
                <?php endif; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="tfoot"><span>The white mark on each bar shows the reorder level.</span><span><?= $canOrder ? '' : 'Tell your manager about anything running out.' ?></span></div>
<?php endif; ?>
</section>
<?php layoutBottom(); ?>
