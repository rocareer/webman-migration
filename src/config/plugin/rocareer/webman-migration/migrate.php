<?php
/**
 * File:        migrate.php
 * Author:      albert <albert@rocareer.com>
 * Created:     2025/5/14 10:49
 * Description:
 *
 * Copyright [2014-2026] [https://rocareer.com]
 * Licensed under the Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */
return [
    "paths"        => [
        "migrations" => "database/migrations",
        "seeds"      => "database/seeds"
    ],
    "table_prefix" => getenv('THINKORM_DEFAULT_PREFIX','ra_') ,
    "environments" => [
        "default_migration_table" => getenv('THINKORM_DEFAULT_PREFIX','ra_') . "migrations",
        "default_environment"     => "dev",
        "dev"                     => [
            "adapter" => 'mysql',
            "host"    => getenv('THINKORM_DEFAULT_HOSTNAME', ''),
            "name"    => getenv('THINKORM_DEFAULT_DATABASE', ''),
            "user"    => getenv('THINKORM_DEFAULT_USERNAME', ''),
            "pass"    => getenv('THINKORM_DEFAULT_PASSWORD', ''),
            "port"    => getenv('THINKORM_DEFAULT_PORT', 3306),
            "charset" => getenv('THINKORM_DEFAULT_CHARSET', 'utf8'),
            "prefix"  => getenv('THINKORM_DEFAULT_PREFIX', 'ra_'), // 确保这里有前缀


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