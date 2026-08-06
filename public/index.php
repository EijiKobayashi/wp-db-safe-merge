<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('wpdbsm_session');
session_start();

(new WpDbSafeMerge\App($config))->run();
