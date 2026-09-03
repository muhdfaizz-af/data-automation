<?php
/**
 * Database connection constants.
 * Isi value sebenar ikut server kau (jangan commit file ni ke git dengan
 * password sebenar - guna .env / server env variable kalau boleh).
 */

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'data_automation');   // <-- tukar ikut nama database kau
define('DB_USER', 'root');          // <-- tukar
define('DB_PASS', '');          // <-- tukar
define('DB_CHARSET', 'utf8mb4');