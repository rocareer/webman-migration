<?php

namespace Rocareer\WebmanMigration\command;

use Rocareer\WebmanMigration\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * migrate:pg —— PostgreSQL 通道迁移
 *
 * 默认跑 database/pg-migrations（各包向量迁移，连接走 PG_* 环境键）；
 * 方案 A（PG 单库终局）下业务表迁移也切 PG：--set=all（或 env PG_MIGRATION_SETS=all）
 * 一并跑 database/migrations —— 业务 + 向量共库共 phinxlog 一次到位。
 * 与 MySQL 通道（migrate:run）共用同一引擎，行为/输出/退出码完全对齐。
 */
#[AsCommand(name: 'migrate:pg', description: 'Run Phinx migrations against PostgreSQL (pg channel)')]
class MigratePgsql extends BaseMigrateCommand
{
    private ?Channel $channel = null;

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('set', null, InputOption::VALUE_REQUIRED, 'PG 迁移集合：vector（默认）|business|all');
    }

    protected function channel(): Channel
    {
        return $this->channel ??= Channel::pg();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $set = strtolower((string) $input->getOption('set'));
        try {
            $this->channel = $set !== '' ? Channel::pg($set) : Channel::pg();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        return $this->runChannel($this->channel, $input, $output);
    }
}
