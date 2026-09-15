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
 * - 起步即迁移文件强检（v2.3.0 撞号 / v2.4.0 命名形态 / v2.5.0 未来日期）：撞号、非 14 位
 *   时间戳前缀、占位未来日期、Phinx 静默忽略、类名重复直接拦截；「年月日+000000」存量警告放行
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
        if ($this->reportMigrationAnomalies($channel, $output)) {
            return self::FAILURE;
        }

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
     * 迁移文件强制预检（v2.3.0 撞号 + v2.4.0 命名形态 + v2.5.0 未来日期）：扫描本次通道装载的
     * 全部迁移目录，「阻断全部迁移」或「静默失效」的文件直接中止；「年月日+000000」存量警告放行。
     *
     * 拦截（FAILURE，任一命中即中止）：
     * - 版本号撞车：Phinx 加载阶段抛 Duplicate migration，不带文件位置且全部迁移跑不了；
     * - 数字前缀 ≠ 14 位（含 8 位「年月日就完了」风）：Phinx 会照常加载（前缀即版本号），
     *   同日多文件/跨包撞号高危，且违反「版本号精确到秒」命名铁律；
     * - 版本号晚于当前时间（占位未来日期）：Phinx 按版本升序执行，未来版本会排到全部真实日期
     *   迁移之后——全新空库上真实日期的业务种子先跑而依赖的表后建，直接断链（2026-09-16
     *   全工作区 65 文件实案根治，TASK-20260916-544）；留 5 分钟时钟偏移余量；
     * - Phinx 静默忽略：不匹配 Phinx 文件名正则的 *.php（名字段非法/无数字前缀）永远不会
     *   被执行——文件躺在目录里造成「已迁移」假象，比报错更危险；
     * - 14 位裸版本号（缺名字段）：Phinx 会加载但类名推导脆弱；
     * - 迁移类名重复：版本号唯一但 class 同名照样 PHP fatal。
     *
     * 警告（放行）：HHMMSS=000000 的存量迁移——新环境全量待执行，一刀切拦截会让所有项目
     * 无法初始化；已执行的不受影响，未执行的建议用 migrate:create 重建（up() 幂等，换号
     * 重跑安全跳过）+ migrate:prune --apply 清旧记录。新建一律 migrate:create，不再产生。
     *
     * 返回 true = 存在拦截项（调用方应返回 FAILURE）。
     */
    private function reportMigrationAnomalies(Channel $channel, OutputInterface $output): bool
    {
        $byVersion = [];
        $byClass = [];
        $badPrefix = [];
        $ignored = [];
        $bare = [];
        $midnight = [];
        $future = [];
        $futureCutoff = date('YmdHis', time() + 300);

        foreach ($channel->migrationPaths() as $path) {
            foreach (glob($path . '/*.php') ?: [] as $file) {
                $base = basename($file);
                if (preg_match('/^(\d+)_/', $base, $m)) {
                    if (strlen($m[1]) !== 14) {
                        $badPrefix[] = $file;
                        continue;
                    }
                    if (!preg_match('/^\d{14}_[a-z][a-z\d]*(?:_[a-z\d]+)*\.php$/i', $base)) {
                        $ignored[] = $file;
                        continue;
                    }
                    $byVersion[$m[1]][] = $file;
                    if (substr($m[1], 8) === '000000') {
                        $midnight[] = $file;
                    }
                    if ($m[1] > $futureCutoff) {
                        $future[] = $file;
                    }
                } elseif (preg_match('/^\d{14}\.php$/', $base)) {
                    $bare[] = $file;
                } else {
                    $ignored[] = $file;
                    continue;
                }
                // 类名查重只看 Phinx 会加载的文件（非法命名的类永远不会被装载，不构成 fatal）
                if (preg_match('/^\s*class\s+(\w+)/m', (string) file_get_contents($file), $c)) {
                    $byClass[$c[1]][] = $file;
                }
            }
        }

        $conflicts = array_filter($byVersion, static fn(array $files): bool => count($files) > 1);
        $classConflicts = array_filter($byClass, static fn(array $files): bool => count($files) > 1);
        $sections = [];
        if ($conflicts !== []) {
            $lines = ['版本号撞车（Phinx 加载即抛 Duplicate migration，全部迁移跑不了）：'];
            foreach ($conflicts as $version => $files) {
                $lines[] = "  版本 {$version} 同时存在：";
                foreach ($files as $file) {
                    $lines[] = '    - ' . $file;
                }
            }
            $sections[] = $lines;
        }
        if ($badPrefix !== []) {
            $lines = ['数字前缀非 14 位时间戳（Phinx 会照常加载且前缀即版本号——8 位「年月日就完了」风撞号高危，违反精确到秒）：'];
            foreach ($badPrefix as $file) {
                $lines[] = '    - ' . $file;
            }
            $sections[] = $lines;
        }
        if ($ignored !== []) {
            $lines = ['Phinx 静默忽略（不匹配迁移文件名正则，永远不会被执行，只是看起来像已迁移）：'];
            foreach ($ignored as $file) {
                $lines[] = '    - ' . $file;
            }
            $sections[] = $lines;
        }
        if ($future !== []) {
            $lines = ['版本号晚于当前时间（占位未来日期——Phinx 按版本升序执行，未来版本排到最后，全新空库上真实日期业务种子先跑而依赖表后建直接断链；违反 migrate:create 真实时间戳铁律）：'];
            foreach ($future as $file) {
                $lines[] = '    - ' . $file;
            }
            $sections[] = $lines;
        }
        if ($bare !== []) {
            $lines = ['14 位裸版本号缺名字段（Phinx 会加载但类名推导脆弱）：'];
            foreach ($bare as $file) {
                $lines[] = '    - ' . $file;
            }
            $sections[] = $lines;
        }
        if ($classConflicts !== []) {
            $lines = ['迁移类名重复（版本号唯一但 class 同名照样 PHP fatal）：'];
            foreach ($classConflicts as $class => $files) {
                $lines[] = "  类 {$class} 同时存在：";
                foreach ($files as $file) {
                    $lines[] = '    - ' . $file;
                }
            }
            $sections[] = $lines;
        }

        if ($sections !== []) {
            $total = count($conflicts, COUNT_RECURSIVE) - count($conflicts)
                + count($badPrefix) + count($ignored) + count($future) + count($bare)
                + count($classConflicts, COUNT_RECURSIVE) - count($classConflicts);
            $output->writeln(sprintf(
                '<error>[%s 通道] 迁移文件预检未通过（%d 个文件异常），已强制中止：</error>',
                $channel->label(),
                $total
            ));
            foreach ($sections as $lines) {
                $output->writeln('<error>  ' . array_shift($lines) . '</error>');
                foreach ($lines as $line) {
                    $output->writeln('<error>' . $line . '</error>');
                }
            }
            $output->writeln(
                "<comment>  修复：新迁移一律 `php webman migrate:create` 生成（真实时间戳精确到秒 + 全局查重自动顺延）；\n" .
                "  未发布/未执行的迁移改号或重建（up() 幂等，换号重跑安全跳过），已执行迁移改号无效、旧记录用 `migrate:prune --apply` 清理；\n" .
                '  名字段非法/无数字前缀的文件改名或移出迁移目录。</comment>'
            );
        }

        if ($midnight !== []) {
            $shown = array_slice($midnight, 0, 5);
            $more = count($midnight) - count($shown);
            $output->writeln(sprintf(
                '<comment>[%s 通道] 「年月日+000000」风格存量迁移 %d 个（警告不拦截；新建禁止此风格）：%s%s；'
                . '已执行的不受影响，未执行的建议 migrate:create 重建 + migrate:prune --apply 清旧记录</comment>',
                $channel->label(),
                count($midnight),
                implode('、', array_map('basename', $shown)),
                $more > 0 ? " 等（另有 {$more} 个）" : ''
            ));
        }

        return $sections !== [];
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
