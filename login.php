<?php
require_once 'layout.php';
if (isLoggedIn()) redirect('dashboard.php');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · PattyTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login">
    <section class="login-art">
        <svg class="halo" viewBox="0 0 800 260" aria-hidden="true"><ellipse cx="400" cy="130" rx="370" ry="88" fill="none" stroke="#FFC43D" stroke-width="12"/></svg>
        <div class="brand"><?= LOGO ?><div><b>PattyTrack</b><small>Angel's Burger · Tarlac Branch</small></div></div>
        <div class="login-copy">
            <h1>Know what's on the shelf before the rush.</h1>
            <p>Every delivery, every patty used, every reorder. One place for the whole crew.</p>
        </div>
        <p class="login-foot">Inventory management for Angel's Burger – Tarlac Branch</p>
    </section>
    <section class="login-form">
        <div class="login-card">
            <h2>Log in</h2>
            <p>Use the account your Branch Manager gave you.</p>
            <?php if (isset($_GET['error'])): ?><div class="login-msg" role="alert">Wrong username or password, or the account is deactivated.</div><?php endif; ?>
            <form action="process.php" method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="login">
                <div class="field"><label for="username">Username</label><input id="username" name="username" required autofocus autocomplete="username"></div>
                <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
                <button class="btn primary" type="submit">Log in</button>
            </form>
            <p class="note">Forgot your password? Ask your Branch Manager to reset it.</p>
            <div class="demo">
                Demo accounts:<br>
                <code>admin / admin123</code> · <code>manager / manager123</code> · <code>staff / staff123</code>
            </div>
        </div>
    </section>
</div>
</body>
</html>
