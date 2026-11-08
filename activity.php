<?php
require_once 'layout.php';
requireRole('Admin', 'Manager', 'Staff');
$isManager = in_array(currentRole(), ['Admin', 'Manager'], true); 

$userId = $isManager ? (int)($_GET['user'] ?? 0) : (int)$_SESSION['user_id'];
$where  = $userId ? 'WHERE a.user_id = ?' : '';
$args   = $userId ? [$userId] : [];

$per   = 12;
$total = $pdo->prepare("SELECT COUNT(*) FROM activity_logs a $where");
$total->execute($args);
$total = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);

$stmt = $pdo->prepare("SELECT a.*, u.full_name name, u.role FROM activity_logs a JOIN users u ON u.user_id = a.user_id $where
                       ORDER BY a.created_at DESC, a.log_id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$stmt->execute($args);
$rows  = $stmt->fetchAll();
$users = $isManager ? $pdo->query('SELECT user_id id, full_name name FROM users ORDER BY full_name')->fetchAll() : [];
$qs = $isManager && $userId ? "&user=$userId" : '';

layoutTop($isManager ? 'Activity log' : 'My activity', 'Audit trail');
?>
<div class="page-head">
    <p><?= $isManager ? 'Who did what, most recent first.' : 'Everything you have recorded in PattyTrack.' ?></p>
    <?php if ($isManager): ?>
    <form method="get"><select name="user" style="width:auto;min-width:180px" aria-label="Filter by user" onchange="this.form.submit()">
        <option value="0">All users</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
    </select></form>
    <?php endif; ?>
</div>
<section class="panel">
<?php if (!$rows): ?>
    <div class="empty"><b>No activity yet</b><span>Actions you take will show up here.</span></div>
<?php else: ?>
    <div class="tbl-wrap"><table>
        <thead><tr><th>When</th><?php if ($isManager): ?><th>User</th><?php endif; ?><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td class="nw"><?= e(when($r['created_at'])) ?></td>
                <?php if ($isManager): ?><td><span class="name"><?= e($r['name']) ?></span><small><?= e(ROLE_NAMES[$r['role']]) ?></small></td><?php endif; ?>
                <td><?= e($r['action_taken']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="tfoot"><span>Page <?= $page ?> of <?= $pages ?> · <?= $total ?> entries</span>
        <div class="row-actions">
            <?php if ($page > 1): ?><a class="btn sm" href="activity.php?page=<?= $page - 1 ?><?= $qs ?>">Previous</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="btn sm" href="activity.php?page=<?= $page + 1 ?><?= $qs ?>">Next</a><?php endif; ?>
        </div></div>
<?php endif; ?>
</section>
<?php layoutBottom(); ?>
