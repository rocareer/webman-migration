<?php

namespace Rocareer\WebmanMigration\command;

use Rocareer\WebmanMigration\Channel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * migrate:run —— MySQL 通道迁移
 *
 * 跑项目 database/migrations + 全部 rocareer 包 database/migrations
 * （动态发现，装新插件即纳入）；连接走 MYSQL_* 环境键。
 * 等价形式：php webman migrate:all（MySQL + PG 两通道）。
 */
#[AsCommand(name: 'migrate:run', description: 'Run Phinx migrations (MySQL channel: database/migrations + vendor/rocareer/*)')]
class MigrateRun extends BaseMigrateCommand
{
    protected function channel(): Channel
    {
        return Channel::mysql();
    }
}
