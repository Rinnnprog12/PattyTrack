<?php

require_once 'process.php';

function icon(string $name): string {
    $paths = [
        'grid'   => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'box'    => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'in'     => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'out'    => '<path d="M12 15V3M7 8l5-5 5 5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'bell'   => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'truck'  => '<path d="M14 18V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h2"/><path d="M15 18h-4"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'clip'   => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/>',
        'chart'  => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'list'   => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'check'  => '<path d="M20 6 9 17l-5-5"/>',
        'menu'   => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'edit'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'tag'    => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1"/>',
        'down'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

const LOGO = '<svg viewBox="0 0 40 40" aria-hidden="true"><ellipse cx="20" cy="6" rx="9" ry="2.8" fill="none" stroke="#FFC43D" stroke-width="2.2"/><path d="M6 21c0-6.2 6.2-10 14-10s14 3.8 14 10z" fill="#FFC43D"/><rect x="5" y="23" width="30" height="3" rx="1.5" fill="#D93A2B"/><rect x="4.5" y="27" width="31" height="4.5" rx="2.25" fill="#9A5230"/><path d="M6 33.5h28a3.5 3.5 0 0 1-3.5 3.5h-21A3.5 3.5 0 0 1 6 33.5z" fill="#FFC43D"/></svg>';

// Small display helpers used by several pages
function meter(array $p): string {
    $max = max($p['reorder_level'] * 2, 1);
    $pct = min(100, $p['stock'] / $max * 100);
    $lvl = !$p['active'] ? 'off' : ($p['stock'] <= $p['reorder_level'] ? 'low' : ($p['stock'] <= $p['reorder_level'] * 1.25 ? 'near' : 'ok'));
    return '<div class="meter ' . $lvl . '"><i style="width:' . round($pct) . '%"></i><b></b></div>';
}
function stockBadge(array $p): string {
    if (!$p['active']) return '<span class="badge off">Inactive</span>';
    if ($p['stock'] == 0) return '<span class="badge out">Out</span>';
    if ($p['stock'] <= $p['reorder_level']) return '<span class="badge low">Low</span>';
    return '<span class="badge ok">OK</span>';
}
// Pending orders are "Awaiting approval" until the Admin approves them
function poStatus(array $po): array {
    if ($po['status'] === 'Pending') return $po['approved_by'] ? ['Approved', 'ready'] : ['Awaiting approval', 'pending'];
    return [$po['status'], strtolower($po['status'])];
}
function poBadge(array $po): string { [$label, $class] = poStatus($po); return '<span class="badge ' . $class . '">' . e($label) . '</span>'; }
function when(string $ts): string {
    $t = strtotime($ts);
    return date('Y-m-d', $t) === date('Y-m-d') ? 'Today ' . date('g:i A', $t) : date('M j, g:i A', $t);
}
function ago(string $ts): string {
    $s = max(0, time() - strtotime($ts));
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' hr ago';
    $d = floor($s / 86400);
    return $d == 1 ? 'Yesterday' : "$d days ago";
}
function initials(string $name): string {
    $o = '';
    foreach (array_slice(explode(' ', $name), 0, 2) as $w) $o .= strtoupper($w[0] ?? '');
    return $o;
}
function categoryOptions($selectedId = 0, string $blank = ''): string {
    $o = $blank ? '<option value="">' . e($blank) . '</option>' : '';
    foreach (categoryList() as $c) $o .= '<option value="' . (int)$c['category_id'] . '"' . ($c['category_id'] == $selectedId ? ' selected' : '') . '>' . e($c['category_name']) . '</option>';
    return $o;
}
const ROLE_NAMES = ['Admin' => 'Administrator', 'Manager' => 'Branch Manager', 'Staff' => 'Staff / Crew'];

function layoutTop(string $title, string $sub = '', string $active = ''): void {
    global $pdo;
    $role   = currentRole();
    $active = $active ?: basename($_SERVER['SCRIPT_NAME']);
    $lowN   = count(lowProducts());
    // Badges: low-stock for everyone; orders awaiting approval for Admin; corrections waiting for Admin/Manager
    $badge  = ['alerts.php' => $lowN];
    if ($role === 'Admin') $badge['purchase_orders.php'] = awaitingPoCount();
    if ($role !== 'Staff') $badge['stock_out.php'] = pendingCorrectionCount();
    $all = ['Admin', 'Manager', 'Staff'];
    $am  = ['Admin', 'Manager'];
    $nav = [
        ['dashboard.php', 'Dashboard', 'grid', $all],
        'Inventory',
        ['products.php', 'Products', 'box', $all],
        ['categories.php', 'Categories', 'tag', $am],
        ['stock_in.php', 'Stock-in', 'in', $am],
        ['stock_out.php', 'Stock-out', 'out', $all],
        ['alerts.php', 'Low-stock alerts', 'bell', $all],
        'Purchasing',
        ['suppliers.php', 'Suppliers', 'truck', $am],
        ['purchase_orders.php', 'Purchase orders', 'clip', $am],
        'Insights',
        ['reports.php', 'Reports', 'chart', $am],
        ['activity.php', $role === 'Staff' ? 'My activity' : 'Activity log', 'list', $all],
        'Administration',
        ['users.php', 'Users', 'users', ['Admin']],
    ];
    $roleName = ROLE_NAMES[$role];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · PattyTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="shell" id="shell">
    <aside class="side">
        <div class="brand"><?= LOGO ?><div><b>PattyTrack</b><small>Angel's Burger · Tarlac</small></div></div>
        <nav class="nav">
<?php
    $group = '';
    foreach ($nav as $n) {
        if (is_string($n)) { $group = $n; continue; }
        [$href, $label, $ic, $roles] = $n;
        if (!in_array($role, $roles, true)) continue;
        if ($group) { echo '<div class="nav-group">' . $group . '</div>'; $group = ''; }
        echo '<a href="' . $href . '"' . ($active === $href ? ' aria-current="page"' : '') . '>' . icon($ic) . e($label);
        if (!empty($badge[$href])) echo '<span class="count">' . (int)$badge[$href] . '</span>';
        echo '</a>';
    }
?>
        </nav>
    </aside>
    <div class="scrim" onclick="document.getElementById('shell').classList.remove('nav-open')"></div>
    <div class="main-col">
        <header class="top">
            <button class="icon-btn menu" type="button" aria-label="Open menu" onclick="document.getElementById('shell').classList.add('nav-open')"><?= icon('menu') ?></button>
            <div><h1><?= e($title) ?></h1><?php if ($sub): ?><p class="sub"><?= e($sub) ?></p><?php endif; ?></div>
            <div class="top-right">
                <a class="icon-btn bell" href="alerts.php" aria-label="Low-stock alerts"><?= icon('bell') ?><?php if ($lowN): ?><span class="dot"><?= $lowN ?></span><?php endif; ?></a>
                <div class="user-chip">
                    <span class="avatar"><?= e(initials($_SESSION['name'])) ?></span>
                    <span class="who"><b><?= e($_SESSION['name']) ?></b><span><?= $roleName ?></span></span>
                </div>
                <form action="process.php" method="post">
                    <?= csrfField() ?><input type="hidden" name="action" value="logout">
                    <button class="btn sm" type="submit"><?= icon('logout') ?>Log out</button>
                </form>
            </div>
        </header>
        <main id="page">
<?php
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="flash ' . e($f['type']) . '" role="status">' . e($f['text']) . '</div>';
    }
}

function layoutBottom(): void {
    echo "        </main>\n    </div>\n</div>\n</body>\n</html>\n";
}

// Chart.js (loaded from a CDN) with the PattyTrack colours
function chartScript(): void { ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
if (window.Chart) {
    Chart.defaults.color = '#A8978A';
    Chart.defaults.font.family = 'Figtree, system-ui, sans-serif';
    Chart.defaults.borderColor = '#3A2D24';
}
function movementChart(id, d) {
    if (!window.Chart) return;
    new Chart(document.getElementById(id), {
        type: d.labels.length > 14 ? 'line' : 'bar',
        data: { labels: d.labels, datasets: [
            { label: 'Stock-in',  data: d.in,  backgroundColor: '#7FDCA0', borderColor: '#7FDCA0', borderRadius: 5, maxBarThickness: 22, tension: .3, pointRadius: 0 },
            { label: 'Stock-out', data: d.out, backgroundColor: '#FFC43D', borderColor: '#FFC43D', borderRadius: 5, maxBarThickness: 22, tension: .3, pointRadius: 0 } ] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ₱' + Math.round(c.parsed.y).toLocaleString() } } },
            scales: { y: { beginAtZero: true, border: { display: false }, grid: { color: '#2E241D' }, ticks: { callback: v => '₱' + (v >= 1000 ? v / 1000 + 'k' : v) } },
                      x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } } } }
    });
}
</script>
<?php }
