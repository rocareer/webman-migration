<?php
/**
 * File      phinx.php
 * Author    albert@rocareer.com
 * Time      2025-04-25 23:17:39
 * Describe  phinx.php
 */
return [
    "paths"        => [
        "migrations" => "database/migrations",
        "seeds"      => "database/seeds"
    ],
    "table_prefix" => "ra_",
    "environments" => [
        "default_migration_table" => env('MYSQL_PREFIX','r_') . env('MYSQL_MIGRATION_TABLE', 'migrations'),
        "default_environment"     => "dev",
        "dev"                     => [
            "adapter" => 'mysql',
            "host"    => env('MYSQL_HOSTNAME', '127.0.0.1'),
            "name"    => env('MYSQL_DATABASE', ''),
            "user"    => env('MYSQL_USERNAME', ''),
            "pass"    => env('MYSQL_PASSWORD', ''),
            "port"    => env('MYSQL_HOSTPORT', 3306),
            "charset" => env('MYSQL_CHARSET', 'utf8'),
            "prefix"  => env('MYSQL_PREFIX', 'r_'), // 确保这里有前缀
        ],
        'production'              => [
            'adapter' => 'mysql',
            'host'    => '127.0.0.1',
            'name'    => 'your_production_database_name',
            'user'    => 'root',
            'pass'    => 'your_password',
            'port'    => '3306',
            'charset' => 'utf8',
            "prefix"  => "rb_", // 确保这里有前缀
        ],
    ]
];