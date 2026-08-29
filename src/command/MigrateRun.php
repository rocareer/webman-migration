<?php

namespace Rocareer\WebmanMigration\command;

use Rocareer\WebmanMigration\Channel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * migrate:run —— 迁移命令（架构无 MySQL：等价 PG 通道全量）
 *
 * 历史命令名保留（README/dev 流程沿用）：跑项目 database/migrations + database/pg-migrations +
 * 全部 rocareer 包同名目录（动态发现，装新插件即纳入）；连接走 PG_* 环境键，
 * 等价 `php webman migrate:pg --set=all`（业务 + 向量共库共 phinxlog 一次跑通）。
 * 旧 MySQL 通道已随 2026-08-29 方案 A 退役。
 */
#[AsCommand(name: 'migrate:run', description: 'Run all pending Phinx migrations (PG channel: business + vector, equals migrate:pg --set=all)')]
class MigrateRun extends BaseMigrateCommand
{
    protected function channel(): Channel
    {
        return Channel::pg(Channel::PG_SET_ALL);
    }
}
