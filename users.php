<?php
require_once 'layout.php';
requireRole('Admin');   // overall account control belongs to the Admin

$users = $pdo->query('SELECT user_id, full_name, username, role, status FROM users ORDER BY user_id')->fetchAll();
layoutTop('Users', 'Accounts and roles');
?>
<div class="grid g-form">
    <section class="panel">
        <div class="panel-h"><h2>Add user</h2></div>
        <div class="panel-b">
            <form action="process.php" method="post">
                <?= csrfField() ?><input type="hidden" name="action" value="create_user">
                <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required></div>
                <div class="two">
                    <div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="off"></div>
                    <div class="field"><label for="role">Role</label>
                        <select id="role" name="role"><?php foreach (ROLE_NAMES as $r => $label): ?><option value="<?= $r ?>" <?= $r === 'Staff' ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="field"><label for="password">Temporary password</label><input id="password" type="text" name="password" minlength="8" required autocomplete="off"><span class="hint">At least 8 characters. Tell them in person.</span></div>
                <button class="btn primary" type="submit"><?= icon('plus') ?>Add user</button>
            </form>
        </div>
    </section>
    <section class="panel">
        <div class="panel-h"><h2>All accounts</h2><span class="meta">Staff cannot reset their own passwords. Do it here.</span></div>
        <div class="tbl-wrap" style="margin-top:10px"><table>
            <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Reset password</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $me = $u['user_id'] == $_SESSION['user_id']; ?>
                <tr>
                    <td><div style="display:flex;align-items:center;gap:10px"><span class="avatar"><?= e(initials($u['full_name'])) ?></span><div><span class="name"><?= e($u['full_name']) ?><?= $me ? ' (you)' : '' ?></span><small><?= e($u['username']) ?></small></div></div></td>
                    <td>
                        <?php if ($me): ?><?= ROLE_NAMES[$u['role']] ?>
                        <?php else: ?>
                        <form class="pw-reset" action="process.php" method="post">
                            <?= csrfField() ?><input type="hidden" name="action" value="update_role"><input type="hidden" name="id" value="<?= (int)$u['user_id'] ?>">
                            <select name="role" aria-label="Role" style="width:140px;height:32px;border-radius:8px" onchange="this.form.submit()">
                                <?php foreach (ROLE_NAMES as $r => $label): ?><option value="<?= $r ?>" <?= $r === $u['role'] ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                            </select>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $u['status'] === 'active' ? 'ok' : 'off' ?>"><?= $u['status'] === 'active' ? 'Active' : 'Deactivated' ?></span></td>
                    <td>
                        <form class="pw-reset" action="process.php" method="post">
                            <?= csrfField() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int)$u['user_id'] ?>">
                            <input type="text" name="password" minlength="8" placeholder="New password" aria-label="New password" required autocomplete="off">
                            <button class="btn sm" type="submit">Reset</button>
                        </form>
                    </td>
                    <td><?php if (!$me): ?>
                        <form action="process.php" method="post" onsubmit="return confirm('Change this account\'s status?');">
                            <?= csrfField() ?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="id" value="<?= (int)$u['user_id'] ?>">
                            <button class="btn ghost sm <?= $u['status'] === 'active' ? 'danger' : '' ?>" type="submit"><?= $u['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?></button>
                        </form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layoutBottom(); ?>
