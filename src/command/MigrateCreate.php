<?php

namespace Rocareer\WebmanMigration\command;

use Rocareer\WebmanMigration\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * migrate:create —— 创建迁移文件（真实时间戳 + 全局查重强制防撞车）
 *
 * 背景：迁移文件名版本号 = 14 位时间戳（YYYYMMDDHHMMSS），手编「日期 + 整点 000000」
 * 风格让分秒位全部浪费，多包同日建迁移极易撞号——Phinx 加载阶段撞号直接抛
 * Duplicate migration 异常，全部迁移（含无关包）都跑不了。本命令从源头杜绝：
 * - 版本号 = 创建那一刻的真实时间（时分秒用满，不再 000000 浪费位）；
 * - 写文件前对全量集合（项目 database/{migrations,pg-migrations} + 全部
 *   vendor/rocareer/* 同名目录，两集合共享 Phinx 版本命名空间）查重，
 *   撞号自动 +1 秒顺延直到空闲——生成的文件必然不撞车；
 * - 顺带查类名重复（版本号唯一但类名同名照样 PHP fatal）；
 * - 产出幂等迁移骨架（中文注释 + up/down，照工作区迁移编写规范）。
 *
 * 运行时强检（v2.4.0）：migrate:run/pg/all/status 起步即拦截撞号/非 14 位前缀/静默忽略/
 * 类名重复，000000 存量警告——本命令生成的文件天然合规，手编文件会被运行时拦下。
 *
 * 用法：
 *   php webman migrate:create add_foo_table                        # 项目 database/migrations/
 *   php webman migrate:create add_foo_vec --dir=pg-migrations      # 项目 database/pg-migrations/（向量集合）
 *   php webman migrate:create add_foo_table --pkg=ai               # vendor/rocareer/ai/database/migrations/
 *                                                #（dev 宿主 vendor 为 symlink，写入即改 src/<pkg>/ 源码）
 * 名字允许 add_foo_table / AddFooTable / add-foo-table，统一规范化为 snake_case。
 *
 * 退出码：0=创建成功；1=参数/目标目录非法或占用扫描后仍无法签发版本。
 */
#[AsCommand(name: 'migrate:create', description: 'Create a migration file with real timestamp (global duplicate check, auto-increment on conflict)')]
class MigrateCreate extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, '迁移名（snake_case，如 add_foo_table）')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, '迁移集合目录：migrations（业务，缺省）| pg-migrations（向量）', 'migrations')
            ->addOption('pkg', 'p', InputOption::VALUE_REQUIRED, '目标 rocareer 包名（缺省 = 项目自身 database/；dev 宿主里写入 symlink 即改包源码）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->normalizeName((string) $input->getArgument('name'));
        if ($name === null) {
            $output->writeln('<error>迁移名只能包含字母/数字/下划线/连字符，且以字母开头（收到：' . $input->getArgument('name') . '）</error>');
            return self::FAILURE;
        }

        $dir = (string) $input->getOption('dir');
        if (!in_array($dir, ['migrations', 'pg-migrations'], true)) {
            $output->writeln('<error>--dir 仅支持 migrations|pg-migrations，实际：' . $dir . '</error>');
            return self::FAILURE;
        }

        $root = function_exists('base_path') ? base_path() : (string) getcwd();
        $targetDir = $this->resolveTargetDir($root, $dir, (string) $input->getOption('pkg'), $output);
        if ($targetDir === null) {
            return self::FAILURE;
        }

        // 全量集合查重（两个集合共享 Phinx 版本命名空间，必须同时扫）
        [$usedVersions, $usedClasses] = $this->scanUsed($root);
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        if (isset($usedClasses[$class])) {
            $output->writeln(sprintf(
                '<error>迁移类名 %s 已存在（%s），请换一个迁移名（版本号唯一但类名同名会 PHP fatal）</error>',
                $class,
                $usedClasses[$class]
            ));
            return self::FAILURE;
        }

        // 签发版本：真实时间起步，撞号 +1 秒顺延（顺延的是生成时间，不改变执行顺序语义）；
        // HHMMSS=000000（整点零分零秒）直接跳过——与运行时「000000 存量警告」口径一致，
        // 本命令签发的版本永不落入「年月日就完了」风格
        $base = (int) date('YmdHis');
        $version = null;
        $bumped = 0;
        for ($ts = $base; $ts < $base + 60; $ts++) {
            if ($ts % 1000000 === 0) {
                $bumped++;
                continue;
            }
            if (!isset($usedVersions[(string) $ts])) {
                $version = (string) $ts;
                break;
            }
            $bumped++;
        }
        if ($version === null) {
            $output->writeln('<error>未来 60 秒的时间戳全部被占用（异常状态），请检查迁移目录后重试</error>');
            return self::FAILURE;
        }

        $file = $targetDir . '/' . $version . '_' . $name . '.php';
        if (is_file($file)) {
            $output->writeln('<error>目标文件已存在：' . $file . '</error>');
            return self::FAILURE;
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            $output->writeln('<error>迁移目录创建失败：' . $targetDir . '</error>');
            return self::FAILURE;
        }
        if (file_put_contents($file, $this->skeleton($version, $name, $class)) === false) {
            $output->writeln('<error>迁移文件写入失败：' . $file . '</error>');
            return self::FAILURE;
        }

        $output->writeln('<info>已创建</info> ' . $file);
        $origin = $bumped > 0 ? sprintf('当前秒被占用，自动顺延 %d 秒', $bumped) : '当前真实时间';
        $output->writeln('<info>版本号</info> ' . $version . '（' . $origin . '）| 类 <comment>' . $class . '</comment> | 集合 <comment>' . $dir . '</comment>');
        $output->writeln('<comment>下一步：补 up()/down() 业务（骨架已按幂等规范生成注释），用 `php webman migrate:run -x` 预检 SQL。</comment>');
        return self::SUCCESS;
    }

    /**
     * 名字规范化：AddFooTable / add-foo-table → add_foo_table；非法返回 null。
     */
    private function normalizeName(string $raw): ?string
    {
        $name = strtolower(trim($raw));
        $name = preg_replace('/[^a-z0-9_-]+/', '_', $name);
        $name = str_replace('-', '_', (string) $name);
        $name = trim((string) $name, '_');
        return preg_match('/^[a-z][a-z0-9_]*$/', $name) ? $name : null;
    }

    /**
     * 解析目标迁移目录：--pkg 给定用 vendor/rocareer/<pkg>/database/<dir>（包必须已安装），
     * 缺省项目自身 database/<dir>（目录允许不存在，创建迁移时顺带 mkdir）。
     * 返回 null 表示已经输出错误。
     */
    private function resolveTargetDir(string $root, string $dir, string $pkg, OutputInterface $output): ?string
    {
        if ($pkg === '') {
            return $root . '/database/' . $dir;
        }
        $pkgRoot = $root . '/vendor/rocareer/' . $pkg;
        if (!is_dir($pkgRoot)) {
            $installed = [];
            foreach (glob($root . '/vendor/rocareer/*', GLOB_ONLYDIR) ?: [] as $p) {
                $installed[] = basename($p);
            }
            $output->writeln(sprintf(
                '<error>包 %s 未安装（vendor/rocareer/%s 不存在）。可选：%s</error>',
                $pkg,
                $pkg,
                $installed === [] ? '（无）' : implode(', ', $installed)
            ));
            return null;
        }
        return $pkgRoot . '/database/' . $dir;
    }

    /**
     * 扫描全量集合（项目 + 全部 vendor/rocareer/* × migrations/pg-migrations）：
     * 返回 [已占版本号 => true, 已用类名 => 文件路径]。
     */
    private function scanUsed(string $root): array
    {
        $versions = [];
        $classes = [];
        foreach (Channel::pg(Channel::PG_SET_ALL)->migrationPaths() as $path) {
            foreach (glob($path . '/*.php') ?: [] as $file) {
                if (preg_match('/^(\d{14})_/', basename($file), $m)) {
                    $versions[$m[1]] = true;
                }
                if (preg_match('/^\s*class\s+(\w+)/m', (string) file_get_contents($file), $m)) {
                    $classes[$m[1]] = $file;
                }
            }
        }
        return [$versions, $classes];
    }

    /**
     * 幂等迁移骨架（照工作区迁移编写规范：无 namespace、中文类注释、up/down 幂等指引）。
     */
    private function skeleton(string $version, string $name, string $class): string
    {
        return <<<PHP
<?php
/**
 * {$name}（一句话用途，创建后修改本行）
 *
 * 幂等迁移：重复执行安全（IF NOT EXISTS / 先查后改守卫）。
 * 版本号 {$version} 由 migrate:create 按真实时间签发并全局查重，禁止手改（改号 = 撞车/重跑事故源）。
 */

use Phinx\Migration\AbstractMigration;

class {$class} extends AbstractMigration
{
    public function up(): void
    {
        // 建表：\$this->execute("CREATE TABLE IF NOT EXISTS ra_xxx (...)");
        // 加列：先查 information_schema.columns 再 ALTER TABLE ADD COLUMN
    }

    public function down(): void
    {
        // 回滚（DROP TABLE IF EXISTS 等，尽量幂等）
    }
}

PHP;
    }
}
