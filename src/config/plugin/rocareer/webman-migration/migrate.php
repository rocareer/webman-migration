<?php
/**
 * Phinx 迁移配置（由 Radmin 核心包安装时写入项目）
 *
 * - 项目迁移：database/migrations
 * - 全部 rocareer 包迁移：vendor/rocareer 下各包 database/migrations 目录（动态扫描，
 *   安装任意全家桶插件即自动纳入，避免逐个 patch 且被后续安装覆盖丢失）
 * 使用绝对路径，避免 Phinx 相对路径解析歧义。
 */
return [
    "paths" => [
        "migrations" => array_values(array_unique(array_merge(
            [__DIR__ . '/../../../../database/migrations'],
            glob(__DIR__ . '/../../../../vendor/rocareer/*/database/migrations') ?: []
        ))),
        "seeds" => __DIR__ . '/../../../../database/seeds',
    ],
    "table_prefix" => getenv('MYSQL_PREFIX', 'ra_'),
    "environments" => [
        "default_migration_table" => getenv('MYSQL_PREFIX', 'ra_') . "migrations",
        "default_environment" => "dev",
        "dev" => [
            "adapter" => 'mysql',
            "host" => getenv('MYSQL_HOSTNAME', '127.0.0.1'),
            "name" => getenv('MYSQL_DATABASE', 'radmin'),
            "user" => getenv('MYSQL_USERNAME', 'root'),
            "pass" => getenv('MYSQL_PASSWORD', '123456'),
            "port" => getenv('MYSQL_HOSTPORT', '3306'),
            "charset" => getenv('MYSQL_CHARSET', 'utf8mb4'),
            "prefix" => getenv('MYSQL_PREFIX', 'ra_'),
        ],
    ],
];
