<?php

namespace Rocareer\WebmanMigration\command;

use Rocareer\WebmanMigration\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * migrate:all —— 一键跑全部通道迁移（部署首选）
 *
 * 按顺序执行：MySQL 通道（migrate:run）→ PostgreSQL 通道（migrate:pg），
 * 任一通道失败立即中止并以非零退出码结束（fail-fast），成功才继续下一通道。
 * --set 作用于 PG 通道（vector|business|all，同 migrate:pg）。
 * 本命令不带 --config/--target（两通道各自的配置与版本号无意义），
 * 需要精细控制请分别用 migrate:run / migrate:pg。
 */
#[AsCommand(name: 'migrate:all', description: 'Run all pending migrations: MySQL channel first, then PostgreSQL (fail fast)')]
class MigrateAll extends BaseMigrateCommand
{
    protected function configure(): void
    {
        $this->addOption('dry-run', 'x', InputOption::VALUE_NONE, '只输出两通道将执行的 SQL，不落库')
            ->addOption('set', null, InputOption::VALUE_REQUIRED, 'PG 通道迁移集合：vector（默认）|business|all');
    }

    protected function phinxOptions(): array
    {
        return ['dry-run'];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $set = strtolower((string) $input->getOption('set'));
        foreach (Channel::all() as $channel) {
            if ($set !== '' && $channel->name() === Channel::PG) {
                try {
                    $channel = Channel::pg($set);
                } catch (\Throwable $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    return self::FAILURE;
                }
            }
            $output->writeln(sprintf(
                '<fg=yellow>===== [%s 通道%s] %s =====</fg=yellow>',
                $channel->label(),
                $channel->pgSetLabel() === '' ? '' : '（' . $channel->pgSetLabel() . '）',
                implode(' + ', $channel->migrationDirs())
            ));
            $code = $this->runChannel($channel, $input, $output);
            if ($code !== self::SUCCESS) {
                $output->writeln(sprintf('<error>%s 通道迁移失败，命令中止（其余通道未执行）。</error>', $channel->label()));
                return self::FAILURE;
            }
        }
        $output->writeln('<info>全部通道迁移完成。</info>');
        return self::SUCCESS;
    }
}
