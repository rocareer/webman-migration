<?php
/**
 * Phinx PostgreSQL 迁移配置（webman-migration v1.1.0，配合 rocareer/memory|knowledge v3 全 PG 向量体系）
 *
 * - 项目 PG 迁移：database/pg-migrations
 * - 全部 rocareer 包 PG 迁移：vendor/rocareer 下各包 database/pg-migrations（动态扫描，与
 *   migrate.php 的 database/migrations 分离：MySQL 迁移跑 MySQL，PG 迁移（向量库建表/索引/幂等搬移）
 *   跑本配置，由 `php webman migrate:pg` 执行）
 * - PG 连接走 PG_* 环境键（PG_HOST/PG_HOSTPORT/PG_USERNAME/PG_PASSWORD/PG_DATABASE/PG_PREFIX），
 *   与 .env 的 PG_* 一致；PG_PREFIX 缺省复用 MYSQL_PREFIX（统一 ra_）
 * 使用绝对路径，避免 Phinx 相对路径解析歧义。
 */
return [
    "paths" => [
        "migrations" => array_values(array_unique(array_merge(
            [__DIR__ . '/../../../../database/pg-migrations'],
            glob(__DIR__ . '/../../../../vendor/rocareer/*/database/pg-migrations') ?: []
        ))),
        "seeds" => __DIR__ . '/../../../../database/seeds',
    ],
    "table_prefix" => getenv('PG_PREFIX') ?: getenv('MYSQL_PREFIX') ?: 'ra_',
    "environments" => [
        "default_migration_table" => getenv('PG_PREFIX') ?: getenv('MYSQL_PREFIX') ?: 'ra_' . "migrations",
        "default_environment" => "pg",
        "pg" => [
            "adapter" => 'pgsql',
            "host" => getenv('PG_HOSTNAME') ?: getenv('PG_HOST') ?: '127.0.0.1',
            "name" => getenv('PG_DATABASE') ?: 'radmin',
            "user" => getenv('PG_USERNAME') ?: 'root',
            "pass" => getenv('PG_PASSWORD') ?: '123456',
            "port" => getenv('PG_HOSTPORT') ?: '5433',
            "schema" => getenv('PG_SCHEMA') ?: 'public',
            "prefix" => getenv('PG_PREFIX') ?: getenv('MYSQL_PREFIX') ?: 'ra_',
        ],
    ],
];
