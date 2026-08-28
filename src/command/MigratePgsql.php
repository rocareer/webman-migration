<?php

namespace Rocareer\WebmanMigration\command;

use Phinx\Console\PhinxApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * migrate:pg 命令（驱动 Phinx 对 PostgreSQL 执行 PG 迁移）
 *
 * 与 migrate:run（MySQL）互补：跑 database/pg-migrations（+ vendor/rocareer/*/database/pg-migrations），
 * 连接走 migrate-pg.php（PG_* 环境键）。用于 rocareer/memory、rocareer/knowledge 的
 * pgvector 向量体系（建表/建索引/幂等搬移存量 MySQL 向量数据）。
 */
class MigratePgsql extends Command
{
    protected static $defaultName = 'migrate:pg';
    protected static $defaultDescription = 'Run Phinx migrations against PostgreSQL (pg-migrations)';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Migration name')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the Phinx configuration file', 'phinx-pg.php')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target version for the migration');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $phinx = new PhinxApplication();
        $phinx->setAutoExit(false);

        $phinxInput = [
            'command' => 'migrate',
        ];

        if ($name = $input->getArgument('name')) {
            $phinxInput['name'] = $name;
        }

        // 配置文件：优先 -c/--config 选项，缺省使用本插件默认 PG 迁移配置
        $config = (string) $input->getOption('config');
        if ($config === '' || $config === 'phinx-pg.php') {
            $config = base_path() . '/config/plugin/rocareer/webman-migration/migrate-pg.php';
        }
        $phinxInput['--configuration'] = $config;

        $output->writeln('Phinx Input: ' . json_encode($phinxInput, JSON_PRETTY_PRINT));

        if ($target = $input->getOption('target')) {
            $phinxInput['--target'] = $target;
        }

        $phinxInput = new ArrayInput($phinxInput);
        $outputBuffer = new BufferedOutput();

        $phinx->run($phinxInput, $outputBuffer);

        $output->writeln($outputBuffer->fetch());

        return self::SUCCESS;
    }
}
