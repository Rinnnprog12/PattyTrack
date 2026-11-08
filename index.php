<?php
require_once 'process.php';
redirect(isLoggedIn() ? 'dashboard.php' : 'login.php');
