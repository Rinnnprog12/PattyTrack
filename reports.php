<?php
require_once 'layout.php';
requireRole('Admin', 'Manager');

$range = $_GET['range'] ?? '7';
$today = date('Y-m-d');
if ($range === 'custom') {
    $from = $_GET['from'] ?? $today;
    $to   = $_GET['to'] ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $from = $to = $today; }
    if ($from > $to) [$from, $to] = [$to, $from];
    if ($to > $today) $to = $today;
    if (strtotime($to) - strtotime($from) > 366 * 86400) $from = date('Y-m-d', strtotime($to . ' -366 days'));
} else {
    $range = $range === '30' ? '30' : '7';
    $to   = $today;
    $from = date('Y-m-d', strtotime("-" . ($range - 1) . " days"));
}

$span = [$from, $to];
$one = function (string $sql) use ($pdo, $span) { $s = $pdo->prepare($sql); $s->execute($span); return (float)$s->fetchColumn(); };
$inRange  = "received_at >= ? AND received_at < DATE_ADD(?, INTERVAL 1 DAY)";
$outRange = "o.status = 'approved' AND o.used_at >= ? AND o.used_at < DATE_ADD(?, INTERVAL 1 DAY)";
$inValue   = $one("SELECT COALESCE(SUM(quantity_received * unit_cost), 0) FROM stock_in s JOIN products p ON p.product_id = s.product_id WHERE s.$inRange");
$outBase   = "FROM stock_out o JOIN products p ON p.product_id = o.product_id WHERE $outRange";
$outValue  = $one("SELECT COALESCE(SUM(quantity_used * unit_cost), 0) $outBase");
$lossValue = $one("SELECT COALESCE(SUM(quantity_used * unit_cost), 0) $outBase AND o.reason IN ('Spoilage','Waste')");
$invValue  = (float)$pdo->query('SELECT COALESCE(SUM(current_stock * unit_cost), 0) FROM products WHERE is_active = 1')->fetchColumn();

$top = $pdo->prepare("SELECT p.product_name name, p.unit_of_measure unit, SUM(o.quantity_used) qty, SUM(o.quantity_used * p.unit_cost) v $outBase GROUP BY p.product_id ORDER BY v DESC LIMIT 6");
$top->execute($span);
$top = $top->fetchAll();

$reasons = $pdo->prepare("SELECT o.reason, SUM(o.quantity_used * p.unit_cost) v $outBase GROUP BY o.reason");
$reasons->execute($span);
$byReason = ['Sale' => 0, 'Spoilage' => 0, 'Waste' => 0, 'Correction' => 0];
foreach ($reasons->fetchAll() as $r) $byReason[$r['reason']] = round((float)$r['v'], 2);

$byCat = $pdo->query('SELECT c.category_name category, COUNT(*) n, SUM(p.current_stock * p.unit_cost) v FROM products p JOIN categories c ON c.category_id = p.category_id
                      WHERE p.is_active = 1 GROUP BY c.category_id ORDER BY c.category_name')->fetchAll();
$catTotal = array_sum(array_column($byCat, 'v'));
$series = dailySeries($from, $to);
$low = lowProducts();

$mv = $pdo->prepare("(SELECT s.received_at ts, 'in' type, p.product_name name, p.unit_of_measure unit, s.quantity_received qty, '' reason, u.full_name who
                      FROM stock_in s JOIN products p ON p.product_id = s.product_id JOIN users u ON u.user_id = s.user_id WHERE s.$inRange)
                     UNION ALL
                     (SELECT o.used_at, 'out', p.product_name, p.unit_of_measure, o.quantity_used, o.reason, u.full_name
                      FROM stock_out o JOIN products p ON p.product_id = o.product_id JOIN users u ON u.user_id = o.user_id WHERE $outRange)
                     ORDER BY ts DESC LIMIT 40");
$mv->execute(array_merge($span, $span));
$movements = $mv->fetchAll();

layoutTop('Reports', 'Inventory and consumption summary');
?>
<div class="print-only"><h1>PattyTrack inventory report</h1>
    <p>Angel's Burger – Tarlac Branch · <?= date('M j, Y', strtotime($from)) ?> to <?= date('M j, Y', strtotime($to)) ?> · Generated <?= date('M j, Y') ?></p></div>

<div class="page-head no-print">
    <form class="row-actions" method="get">
        <div class="seg">
            <a href="reports.php?range=7" class="<?= $range === '7' ? 'on' : '' ?>">Last 7 days</a>
            <a href="reports.php?range=30" class="<?= $range === '30' ? 'on' : '' ?>">Last 30 days</a>
            <a href="reports.php?range=custom&from=<?= e($from) ?>&to=<?= e($to) ?>" class="<?= $range === 'custom' ? 'on' : '' ?>">Custom</a>
        </div>
        <?php if ($range === 'custom'): ?>
            <input type="hidden" name="range" value="custom">
            <input type="date" name="from" value="<?= e($from) ?>" max="<?= $today ?>" aria-label="From date" style="width:auto">
            <span style="color:var(--muted)">to</span>
            <input type="date" name="to" value="<?= e($to) ?>" max="<?= $today ?>" aria-label="To date" style="width:auto">
            <button class="btn sm" type="submit">Apply</button>
        <?php endif; ?>
    </form>
    <button class="btn" type="button" onclick="window.print()"><?= icon('down') ?>Export to PDF</button>
</div>

<section class="kpis">
    <div class="kpi"><span class="kpi-v"><?= peso($inValue, 0) ?></span><span class="kpi-k">Stock-in value</span></div>
    <div class="kpi"><span class="kpi-v"><?= peso($outValue, 0) ?></span><span class="kpi-k">Stock-out value</span></div>
    <div class="kpi <?= $lossValue ? 'red' : '' ?>"><span class="kpi-v"><?= peso($lossValue, 0) ?></span><span class="kpi-k">Lost to spoilage and waste</span></div>
    <div class="kpi"><span class="kpi-v"><?= peso($invValue, 0) ?></span><span class="kpi-k">Inventory value today</span></div>
</section>

<section class="panel">
    <div class="panel-h"><h2>Stock movement</h2>
        <div class="legend"><span><i style="background:#7FDCA0"></i>In</span><span><i style="background:#FFC43D"></i>Out</span></div></div>
    <div class="panel-b"><div class="chart-box tall"><canvas id="chMove"></canvas></div></div>
</section>

<div class="grid g-7-5 mt">
    <section class="panel">
        <div class="panel-h"><h2>Top-consumed items</h2><span class="meta">By cost</span></div>
        <div class="panel-b">
            <?php if (!$top): ?><div class="empty"><b>No stock-out in this period</b></div><?php endif; ?>
            <?php foreach ($top as $t): ?>
                <div class="bar-row">
                    <span><b><?= e($t['name']) ?></b><small style="display:block;color:var(--muted)"><?= (int)$t['qty'] ?> <?= e($t['unit']) ?></small></span>
                    <div class="share"><i style="width:<?= $top[0]['v'] ? round($t['v'] / $top[0]['v'] * 100) : 0 ?>%"></i></div>
                    <span style="text-align:right"><?= peso($t['v'], 0) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="panel">
        <div class="panel-h"><h2>Stock-out breakdown</h2><span class="meta">By cost</span></div>
        <div class="panel-b"><div class="chart-box"><canvas id="chReason"></canvas></div></div>
    </section>
</div>

<div class="grid g-form mt" style="grid-template-columns:minmax(0,5fr) minmax(0,7fr)">
    <section class="panel">
        <div class="panel-h"><h2>Low-stock items</h2><span class="meta"><?= count($low) ?> now</span></div>
        <div class="panel-b">
            <?php if (!$low): ?><div class="empty"><span class="ring"><?= icon('check') ?></span><b>All stocked up</b></div><?php endif; ?>
            <?php foreach ($low as $p): ?>
                <div class="alert-row"><div><b><?= e($p['name']) ?></b><small><?= $p['stock'] == 0 ? 'Out of stock' : e($p['stock'] . ' ' . $p['unit'] . ' left') ?> · reorder at <?= (int)$p['reorder_level'] ?></small></div><?= meter($p) ?></div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="panel">
        <div class="panel-h"><h2>Stock movements</h2><span class="meta">Latest <?= count($movements) ?> in this period</span></div>
        <div class="tbl-wrap" style="margin-top:10px"><table>
            <thead><tr><th>When</th><th>Product</th><th class="num">Qty</th><th>Type</th><th>By</th></tr></thead>
            <tbody>
            <?php if (!$movements): ?><tr><td colspan="5" style="color:var(--muted)">No movements in this period.</td></tr><?php endif; ?>
            <?php foreach ($movements as $m): ?>
                <tr><td class="nw"><?= e(when($m['ts'])) ?></td><td><span class="name"><?= e($m['name']) ?></span></td>
                    <td class="num"><b><?= $m['type'] === 'in' ? '+' : '−' ?><?= (int)$m['qty'] ?></b> <span style="color:var(--muted)"><?= e($m['unit']) ?></span></td>
                    <td><?= $m['type'] === 'in' ? '<span class="badge ok">Stock-in</span>' : '<span class="tag ' . e($m['reason']) . '">' . e($m['reason']) . '</span>' ?></td>
                    <td><?= e(explode(' ', $m['who'])[0]) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>

<section class="panel mt">
    <div class="panel-h"><h2>Inventory valuation by category</h2><span class="meta">Active products, at unit cost</span></div>
    <div class="tbl-wrap" style="margin-top:10px"><table>
        <thead><tr><th>Category</th><th class="num">Items</th><th class="num">Value</th><th style="min-width:160px">Share</th></tr></thead>
        <tbody>
        <?php foreach ($byCat as $c): $pct = $catTotal ? $c['v'] / $catTotal * 100 : 0; ?>
            <tr><td><span class="name"><?= e($c['category']) ?></span></td><td class="num"><?= (int)$c['n'] ?></td><td class="num"><?= peso($c['v']) ?></td>
                <td><div style="display:flex;align-items:center;gap:10px"><div class="share" style="flex:1"><i style="width:<?= round($pct) ?>%"></i></div><span style="width:40px;text-align:right;color:var(--muted)"><?= round($pct) ?>%</span></div></td></tr>
        <?php endforeach; ?>
            <tr><td><b>Total</b></td><td class="num"><b><?= array_sum(array_column($byCat, 'n')) ?></b></td><td class="num"><b><?= peso($catTotal) ?></b></td><td></td></tr>
        </tbody>
    </table></div>
</section>

<?php chartScript(); ?>
<script>
movementChart('chMove', <?= json_encode($series) ?>);
if (window.Chart) {
    const r = <?= json_encode($byReason) ?>;
    const colors = { Sale: '#FFC43D', Spoilage: '#FF8A77', Waste: '#F5934A', Correction: '#9FB0C8' };
    new Chart(document.getElementById('chReason'), {
        type: 'doughnut',
        data: { labels: Object.keys(r), datasets: [{ data: Object.values(r), backgroundColor: Object.keys(r).map(k => colors[k]), borderColor: '#1E1712', borderWidth: 3 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                       tooltip: { callbacks: { label: c => ' ' + c.label + ': ₱' + Math.round(c.parsed).toLocaleString() } } } }
    });
}
</script>
<?php layoutBottom(); ?>
