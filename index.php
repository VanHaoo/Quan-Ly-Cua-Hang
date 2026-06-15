<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

header('Location: ' . BASE_URL . (is_logged_in() ? '/dashboard.php' : '/login.php'));
exit;
