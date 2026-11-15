<?php

// Patch Laravel framework base database config for PHP 8.5 deprecation of PDO::MYSQL_ATTR_SSL_CA
$frameworkConfig = __DIR__.'/../vendor/laravel/framework/config/database.php';

if (file_exists($frameworkConfig)) {
    $content = file_get_contents($frameworkConfig);
    // If it already uses defined('Pdo\\Mysql::ATTR_SSL_CA'), nothing to do
    if (strpos($content, "defined('Pdo\\Mysql::ATTR_SSL_CA')") === false) {
        $replacement = "(defined('Pdo\\Mysql::ATTR_SSL_CA') ? \\Pdo\\Mysql::ATTR_SSL_CA : \\PDO::MYSQL_ATTR_SSL_CA)";
        $patched = str_replace('PDO::MYSQL_ATTR_SSL_CA', $replacement, $content);
        file_put_contents($frameworkConfig, $patched);
    }
}
