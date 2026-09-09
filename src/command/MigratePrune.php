<?php

namespace Rocareer\WebmanMigration\command;

use Rocareer\WebmanMigration\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * migrate:prune —— 清理「已记录但文件缺失」的历史迁移记录
 *
 * 背景：迁移文件改名/下架后，迁移记录表里残留旧版本行（migrate:status 的 missing 项，
 * 退出码 2）。这类记录不影响 migrate:run（Phinx 不会重跑），但会让 status/CI 长期带红。
 * 本命令对照 PG 全量集合（业务 + 向量全部迁移目录）的现存文件版本，列出并清理
 * 记录表中没有对应文件的行——只动记录表，不碰任何业务表。
 *
 * 用法：
 *   php webman migrate:prune            # 预检（dry-run：只列出待清理记录）
 *   php webman migrate:prune --apply    # 实际删除缺失记录
 *
 * 退出码：0=无缺失或已清理；1=运行错误；2=存在缺失记录但未 --apply（对齐 status 语义）。
 */
#[AsCommand(name: 'migrate:prune', description: 'Prune migration records whose files are missing (PG channel)')]
class MigratePrune extends Command
{
    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, '实际删除缺失记录（缺省 dry-run 只列出）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 固定全量集合：prune 必须对照「全部迁移目录」，按子集合对照会误删其它集合的记录
        $channel = Channel::pg(Channel::PG_SET_ALL);
        $conn = $channel->connection();
        $table = $channel->migrationTable();

        // 现存迁移文件版本集合（项目 + vendor/rocareer/* 全部目录）
        $versions = [];
        foreach ($channel->migrationPaths() as $path) {
            foreach (glob($path . '/*.php') ?: [] as $file) {
                if (preg_match('/^(\d{14})_/', basename($file), $m)) {
                    $versions[$m[1]] = true;
                }
            }
        }

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $conn['host'], $conn['port'], $conn['name']);
        try {
            $pdo = new \PDO($dsn, $conn['user'], $conn['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            $output->writeln('<error>数据库连接失败（检查 PG_* 环境键）：' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        try {
            $rows = $pdo->query("SELECT version, migration_name FROM {$table} ORDER BY version")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $output->writeln('<error>迁移记录表读取失败（先执行 migrate:run 建表）：' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $missing = array_values(array_filter($rows, fn (array $r): bool => !isset($versions[$r['version']])));
        if (!$missing) {
            $output->writeln(sprintf('<info>无缺失记录（%d 条记录全部有对应文件）</info>', count($rows)));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<comment>缺失文件的历史记录 %d 条（现存迁移文件 %d 个）：</comment>', count($missing), count($versions)));
        foreach ($missing as $row) {
            $output->writeln(sprintf('  %s  %s', $row['version'], $row['migration_name']));
        }
        if (!$input->getOption('apply')) {
            $output->writeln('<comment>dry-run：确认无误后加 --apply 删除以上记录</comment>');
            return 2;
        }

        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE version = ?");
        foreach ($missing as $row) {
            $stmt->execute([$row['version']]);
        }
        $output->writeln(sprintf('<info>已清理 %d 条缺失记录</info>', count($missing)));
        return Command::SUCCESS;
    }
}
