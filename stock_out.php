<?php
require_once 'layout.php';
requireRole('Admin', 'Manager', 'Staff');
$canApprove = in_array(currentRole(), ['Admin', 'Manager'], true);

$products = $pdo->query(productSql('p.is_active = 1'))->fetchAll();
$recent   = $pdo->query("SELECT o.*, p.product_name, p.unit_of_measure unit, u.full_name
                         FROM stock_out o JOIN products p ON p.product_id = o.product_id JOIN users u ON u.user_id = o.user_id
                         ORDER BY o.used_at DESC, o.stock_out_id DESC LIMIT 9")->fetchAll();
$today    = $pdo->query("SELECT COUNT(*) FROM stock_out WHERE status <> 'rejected' AND DATE(used_at) = CURDATE()")->fetchColumn();
$pendingN = pendingCorrectionCount();
layoutTop('Stock-out', 'Usage, spoilage, waste and corrections');
?>
<?php if ($canApprove && $pendingN): ?>
    <div class="panel" style="padding:14px 18px;margin-bottom:18px;border-color:var(--gold)">
        <b><?= $pendingN ?> correction<?= $pendingN > 1 ? 's' : '' ?> waiting for your approval.</b>
        <span style="color:var(--muted)">Approve or reject them in the list below.</span>
    </div>
<?php endif; ?>
<div class="grid g-form">
    <section class="panel">
        <div class="panel-h"><h2>Record usage or loss</h2></div>
        <div class="panel-b">
            <form action="process.php" method="post">
                <?= csrfField() ?><input type="hidden" name="action" value="stock_out">
                <div class="field"><label for="product_id">Product</label>
                    <select id="product_id" name="product_id" required>
                        <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> — <?= (int)$p['stock'] ?> <?= e($p['unit']) ?> on hand</option><?php endforeach; ?>
                    </select></div>
                <div class="field"><label for="quantity">Quantity</label><input id="quantity" type="number" name="quantity" min="1" step="1" required placeholder="0"></div>
                <div class="field"><span class="lbl">Reason</span>
                    <div class="chips">
                        <?php $i = 0; foreach (REASONS as $r => $desc): ?>
                            <label class="chip" title="<?= e($desc) ?>"><input type="radio" name="reason" value="<?= $r ?>" <?= $i++ ? '' : 'checked' ?>><span><?= $r ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <span class="hint">Sale = customer orders · Spoilage = expired · Waste = dropped or burnt · Correction = fix a wrong count<?= $canApprove ? '' : ' (needs approval)' ?>.</span></div>
                <div class="field"><label for="note">Note</label><input id="note" name="note" maxlength="120" placeholder="Optional. What happened?"></div>
                <button class="btn primary" type="submit" style="width:100%;height:42px"><?= icon('check') ?>Record stock-out</button>
            </form>
        </div>
    </section>
    <section class="panel">
        <div class="panel-h"><h2>Recent stock-out</h2><span class="meta"><?= $today ?> logged today</span></div>
        <div class="tbl-wrap" style="margin-top:10px"><table>
            <thead><tr><th>When</th><th>Product</th><th class="num">Qty</th><th>Reason</th><th>By</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="nw"><?= e(when($r['used_at'])) ?></td>
                    <td><span class="name"><?= e($r['product_name']) ?></span><?php if ($r['note']): ?><small><?= e($r['note']) ?></small><?php endif; ?></td>
                    <td class="num"><b>−<?= (int)$r['quantity_used'] ?></b> <span style="color:var(--muted)"><?= e($r['unit']) ?></span></td>
                    <td><span class="tag <?= e($r['reason']) ?>"><?= e($r['reason']) ?></span></td>
                    <td><?= e(explode(' ', $r['full_name'])[0]) ?></td>
                    <td>
                    <?php if ($r['status'] === 'pending' && $canApprove): ?>
                        <div class="acts" style="justify-content:flex-start">
                            <form action="process.php" method="post"><?= csrfField() ?><input type="hidden" name="action" value="approve_correction"><input type="hidden" name="id" value="<?= (int)$r['stock_out_id'] ?>"><button class="btn sm gold" type="submit">Approve</button></form>
                            <form action="process.php" method="post"><?= csrfField() ?><input type="hidden" name="action" value="reject_correction"><input type="hidden" name="id" value="<?= (int)$r['stock_out_id'] ?>"><button class="btn sm ghost danger" type="submit">Reject</button></form>
                        </div>
                    <?php elseif ($r['status'] === 'pending'): ?>
                        <span class="badge pending">Awaiting approval</span>
                    <?php else: ?>
                        <span class="badge <?= $r['status'] ?>"><?= $r['status'] === 'approved' ? 'Recorded' : 'Rejected' ?></span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layoutBottom(); ?>
