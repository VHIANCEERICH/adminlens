<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

adminlens_require_role('admin');
adminlens_redirect(adminlens_url('/index.php'));
