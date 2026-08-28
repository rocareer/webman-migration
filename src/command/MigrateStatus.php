<?php

namespace Rocareer\WebmanMigration\command;

use Rocareer\WebmanMigration\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

/**
 * migrate:status —— 迁移状态查看（运维必备）
 *
 * 缺省列出全部通道（MySQL → PG）的已执行/待执行迁移；
 * --channel=mysql|pg 只看单通道；--json 配合单通道输出机器可读 JSON（供 CI/监控解析）。
 * 退出码透传 phinx status：0=全部已执行；1=运行错误；2=存在「已记录但文件缺失/重命名」
 * 的历史版本（安全，不会重跑）；3=存在未执行迁移。
 */
#[AsCommand(name: 'migrate:status', description: 'Show migration status for MySQL and PostgreSQL channels')]
class MigrateStatus extends BaseMigrateCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('channel', null, InputOption::VALUE_REQUIRED, '只看指定通道：mysql|pg（缺省全部）')
            ->addOption('json', null, InputOption::VALUE_NONE, '以 JSON 输出（仅适用于 --channel 单通道）')
            ->addOption('set', null, InputOption::VALUE_REQUIRED, 'PG 通道迁移集合：vector（默认）|business|all');
    }

    protected function phinxCommand(): string
    {
        return 'status';
    }

    protected function phinxOptions(): array
    {
        return ['environment'];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $wantJson = (bool) $input->getOption('json');
        $channel = strtolower((string) $input->getOption('channel'));
        $set = strtolower((string) $input->getOption('set'));

        $channels = Channel::all();
        if ($channel !== '') {
            if ($channel !== Channel::MYSQL && $channel !== Channel::PG) {
                $output->writeln('<error>--channel 仅支持 mysql|pg，实际为：' . $channel . '</error>');
                return Command::FAILURE;
            }
            $channels = [$channel === Channel::PG ? Channel::pg($set !== '' ? $set : null) : Channel::mysql()];
        }
        if ($wantJson && count($channels) > 1) {
            $output->writeln('<error>--json 只支持单通道，请加 --channel=mysql 或 --channel=pg</error>');
            return Command::FAILURE;
        }

        // 退出码透传 phinx status 语义：0=全部已执行；1=运行错误；2=存在已记录但文件缺失/重命名
        // （安全，历史版本已被记录不会重跑）；3=存在未执行迁移
        $failed = false;
        $worst = 0;
        foreach ($channels as $one) {
            if ($set !== '' && $one->name() === Channel::PG) {
                try {
                    $one = Channel::pg($set);
                } catch (\Throwable $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    return Command::FAILURE;
                }
            }
            if (!$wantJson) {
                $output->writeln(sprintf(
                    '<fg=yellow>===== [%s 通道%s] %s =====</fg=yellow>',
                    $one->label(),
                    $one->pgSetLabel() === '' ? '' : '（' . $one->pgSetLabel() . '）',
                    implode(' + ', $one->migrationDirs())
                ));
            }
            if ($wantJson) {
                // JSON 模式需要纯净输出：缓存 phinx 全部输出后提取 JSON 载荷再转发
                $buf = new BufferedOutput();
                $code = $this->runChannel($one, $input, $buf, ['format' => 'json']);
                $payload = '';
                if (preg_match('/\{.*\}/s', $buf->fetch(), $m)) {
                    $payload = $m[0];
                }
                if ($payload === '') {
                    $output->writeln('<error>无法从 phinx 输出中提取 JSON 载荷</error>');
                    return Command::FAILURE;
                }
                $output->writeln($payload);
            } else {
                $code = $this->runChannel($one, $input, $output);
            }
            if ($code > $worst) {
                $worst = $code;
            }
            if ($code === Command::FAILURE) {
                $failed = true; // 1 = phinx 自身运行错误
            }
        }
        if ($failed) {
            return Command::FAILURE;
        }
        return $worst; // 0 / 2 / 3 原样透传
    }
}
