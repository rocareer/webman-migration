<?php

namespace Rocareer\WebmanMigration;

/**
 * Phinx 配置文件生成器（v2 核心：配置即代码，宿主零迁移配置文件）。
 *
 * - 输入 = 通道 + 环境变量；同等输入必得同等输出（确定性生成），审计、排查、复现都容易
 * - 产物写入 runtime 目录（runtime/plugin/webman-migration/migrate-<通道>.php），
 *   内容未变化不落盘 —— 避免文件 mtime 变化触发 webman 监控全量 reload 打断在途任务
 * - 写入采用临时文件 + rename 原子替换，杜绝半截文件
 * - 需要自定义时用 --config 指向自己的 phinx 配置文件，本生成器不再介入
 */
final class PhinxConfig
{
    /** 生成（或内容变化时刷新）通道 phinx 配置文件，返回绝对路径 */
    public static function write(Channel $channel): string
    {
        $file = self::runtimeDir() . '/migrate-' . $channel->name() . '.php';
        $content = self::render($channel);
        if (!is_file($file) || sha1_file($file) !== sha1($content)) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            $tmp = $file . '.' . getmypid() . '.tmp';
            file_put_contents($tmp, $content);
            rename($tmp, $file);
        }
        return $file;
    }

    /** 渲染通道 phinx 配置内容（PHP 文件：return 数组） */
    public static function render(Channel $channel): string
    {
        $config = [
            'paths' => [
                'migrations' => $channel->migrationPaths(),
            ],
            'environments' => [
                'default_migration_table' => $channel->migrationTable(),
                'default_environment'     => $channel->name(),
                $channel->name()          => $channel->connection(),
            ],
        ];
        // 项目 database/seeds 存在时保留 phinx seed 能力（缺失不生成，避免无用配置）
        if (is_dir((function_exists('base_path') ? base_path() : (string) getcwd()) . '/database/seeds')) {
            $config['paths']['seeds'] = (function_exists('base_path') ? base_path() : (string) getcwd()) . '/database/seeds';
        }

        return "<?php\n/**\n"
            . " * 自动生成：rocareer/webman-migration（" . $channel->label() . " 通道）\n"
            . " * 内容由环境变量驱动且确定性生成，请勿手工编辑；自定义请用 --config 指定自己的配置文件。\n"
            . " */\n"
            . 'return ' . var_export($config, true) . ";\n";
    }

    /** 生成目录：优先 webman runtime（runtime_path()），不可用回退系统临时目录 */
    public static function runtimeDir(): string
    {
        if (function_exists('runtime_path')) {
            return runtime_path() . '/plugin/webman-migration';
        }
        return sys_get_temp_dir() . '/webman-migration';
    }
}
