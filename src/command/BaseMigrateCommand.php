<?php

namespace Rocareer\WebmanMigration\command;

use Phinx\Console\PhinxApplication;
use Rocareer\WebmanMigration\Channel;
use Rocareer\WebmanMigration\PhinxConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 迁移命令基类：把 Phinx 驱动引擎收敛到一处（配置生成/参数透传/退出码透传）。
 *
 * 可靠性约定（v2）：
 * - 真正确认执行结果：Phinx 的退出码原样透传给 webman CLI（v1 恒返回 0，失败被吞掉）
 * - 配置由 PhinxConfig 按 env 生成且确定性，宿主无迁移配置文件、运行期零改写
 * - 自定义 phinx 配置与 phinx 原生子命令（migrate/status/rollback/seed:run 等）
 *   均通过 --config 支持，本包不绕过 phinx 任何能力
 *
 * 子类只声明三件事：通道（channel()）、phinx 子命令名（phinxCommand()）、
 * 允许透传的 phinx 选项（phinxOptions()）。
 */
abstract class BaseMigrateCommand extends Command
{
    /**
     * 本命令服务的通道（架构无 MySQL：缺省即 PG 全量；migrate:pg 可切 --set）
     */
    protected function channel(): Channel
    {
        return Channel::pg(Channel::PG_SET_ALL);
    }

    /** phinx 子命令名：migrate（默认）| status */
    protected function phinxCommand(): string
    {
        return 'migrate';
    }

    /** 允许透传给 phinx 子命令的选项（按子命令能力声明，未定义的选项透传会直接抛错） */
    protected function phinxOptions(): array
    {
        return ['environment', 'target', 'date', 'count', 'dry-run'];
    }

    protected function configure(): void
    {
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, '自定义 phinx 配置文件（缺省由包自动生成）')
            ->addOption('environment', 'e', InputOption::VALUE_REQUIRED, '目标环境（缺省用配置文件 default_environment）')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, '迁移到指定版本号')
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, '迁移到指定日期（YYYYMMDD）')
            ->addOption('count', 'k', InputOption::VALUE_REQUIRED, '仅执行最近 N 个待执行迁移')
            ->addOption('dry-run', 'x', InputOption::VALUE_NONE, '只输出将执行的 SQL，不落库');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runChannel($this->channel(), $input, $output);
    }

    /**
     * 驱动 phinx 执行一个通道的子命令，且把 phinx 退出码作为本命令退出码。
     * （MigrateAll 复用此方法按通道逐个执行，失败即止；$forced 为绕过输入直接追加的 phinx 选项。）
     */
    protected function runChannel(Channel $channel, InputInterface $input, OutputInterface $output, array $forced = []): int
    {
        $configPath = $this->resolveConfig($channel, $input, $output);
        if ($configPath === null) {
            return self::FAILURE;
        }

        $conn = $channel->connection();
        $setLabel = $channel->pgSetLabel();
        $sources = array_map(function (string $path): string {
            if (preg_match('#/vendor/rocareer/([^/]+)/database/#', $path, $m)) {
                return $m[1];
            }
            return '项目';
        }, $channel->migrationPaths());
        $sources = array_values(array_unique($sources));
        $sourceLabel = count($sources) > 8 ? implode('·', array_slice($sources, 0, 8)) . ' 等' : implode('·', $sources);
        $output->writeln(sprintf(
            '<info>[%s 通道%s]</info> 来源：<comment>%s</comment> | 数据库 <comment>%s</comment> · %s@%s:%s | 迁移记录表 <comment>%s</comment> | 迁移目录 <comment>%d</comment> 个',
            $channel->label(),
            $setLabel === '' ? '' : '（' . $setLabel . '）',
            $sourceLabel,
            $conn['name'],
            $conn['user'],
            $conn['host'],
            $conn['port'],
            $channel->migrationTable(),
            count($channel->migrationPaths())
        ));
        $output->writeln('<info>使用配置</info> ' . (string) $configPath);

        $phinxInput = ['command' => $this->phinxCommand(), '--configuration' => (string) $configPath];
        foreach ($this->phinxOptions() as $option) {
            if (!$input->hasOption($option)) {
                continue;
            }
            $value = $input->getOption($option);
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            $phinxInput['--' . $option] = $value;
        }
        foreach ($forced as $option => $value) {
            $phinxInput['--' . $option] = $value;
        }

        $phinx = new PhinxApplication();
        $phinx->setAutoExit(false);
        try {
            $code = $phinx->run(new ArrayInput($phinxInput), $output);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>[%s 通道] 运行失败：%s</error>', $channel->label(), $e->getMessage()));
            return self::FAILURE;
        }
        $output->writeln(sprintf('<info>[%s 通道] 结束，退出码 %d</info>', $channel->label(), $code));

        // phinx 退出码原样透传（0=成功；migrate 失败为 1；status 另有 2=记录缺文件 / 3=存在未执行），
        // CI/部署脚本可依赖非零判断
        return $code;
    }

    /**
     * 解析 phinx 配置文件路径：--config 给定则用它（校验存在），缺省按通道自动生成。
     * （migrate:all 未定义 --config 选项——两通道各用各的生成配置，此处按 hasOption 规避。）
     * 返回 null 表示已经输出错误（调用方应返回 FAILURE）。
     */
    private function resolveConfig(Channel $channel, InputInterface $input, OutputInterface $output): ?string
    {
        if ($input->hasOption('config')) {
            $given = (string) $input->getOption('config');
            if ($given !== '') {
                if (!is_file($given)) {
                    $output->writeln('<error>未找到自定义 phinx 配置文件：' . $given . '</error>');
                    return null;
                }
                return $given;
            }
        }
        return PhinxConfig::write($channel);
    }
}
