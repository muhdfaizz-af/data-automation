<?php
require_once __DIR__ . '/../config/db.php';

define('ADMIN_SOURCE_BASE_URL', getenv('ADMIN_SOURCE_BASE_URL') ?: 'https://admin.e-saudagaar.com');
define('ADMIN_SOURCE_USERNAME', getenv('ADMIN_SOURCE_USERNAME') ?: 'system');
define('ADMIN_SOURCE_PASSWORD', getenv('ADMIN_SOURCE_PASSWORD') ?: '123456');
