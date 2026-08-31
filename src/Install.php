<?php

namespace Rocareer\WebmanMigration;

/**
 * 插件安装/卸载钩子（WEBMAN_PLUGIN）
 *
 * v2 只落盘两份接线配置（app.php / command.php）：
 * - 迁移配置不落盘 —— 由 PhinxConfig 按环境变量确定性生成（runtime/plugin/webman-migration/），
 *   宿主零配置文件、零运行期改写，包升级不再互相覆盖
 * - 旧版（v1）曾落盘 migrate.php / migrate-pg.php：检测到即改名 .bak 保留（`--config` 仍可
 *   指向自定义 phinx 配置文件）
 */
class Install
{
    const WEBMAN_PLUGIN = true;

    /** @var array 源 => 目标（相对项目根） */
    protected static $pathRelation = [
        'config/plugin/rocareer/webman-migration' => 'config/plugin/rocareer/webman-migration',
    ];

    /**
     * 安装（首次或更新）
     *
     * @param bool $isFirst 是否首次安装（composer require 时为 true，update 回退时为 false）
     * @return void
     */
    public static function install($isFirst = true): void
    {
        static::installByRelation();
        static::retireLegacyConfigs();
    }

    /**
     * 更新：刷新接线配置 + 旧配置退役（升级专属钩子，官方 Plugin::update 调用）
     * @return void
     */
    public static function update(): void
    {
        static::installByRelation();
        static::retireLegacyConfigs();
    }

    /**
     * 卸载
     * @return void
     */
    public static function uninstall(): void
    {
        self::uninstallByRelation();
    }

    /**
     * v1→v2 过渡：宿主 config 下残留的迁移配置文件改名 .bak
     * （v2 由代码生成配置，旧文件不再被读取，保留以防自定义内容丢失）
     * @return void
     */
    protected static function retireLegacyConfigs(): void
    {
        $dir = base_path() . '/config/plugin/rocareer/webman-migration';
        foreach (['migrate.php', 'migrate-pg.php'] as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                rename($path, $path . '.bak');
                echo "v2 起迁移配置由代码按环境变量生成：旧配置 $file 已备份为 $file.bak（无自定义可删除）\n";
            }
        }
    }

    /**
     * 拷贝接线配置到宿主项目（overwrite=true：插件配置以包内为准，升级刷新命令注册等，
     * 防止 webman copy_dir 默认「存在即跳过」留下宿主旧版配置漂移）
     * @return void
     */
    public static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parent_dir = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parent_dir)) {
                    mkdir($parent_dir, 0777, true);
                }
            }
            copy_dir(__DIR__ . "/$source", base_path() . "/$dest", true);
            echo "Create $dest\n";
        }
    }

    /**
     * 移除拷贝到宿主项目的接线配置
     * @return void
     */
    public static function uninstallByRelation(): void
    {
        foreach (array_reverse(static::$pathRelation) as $source => $dest) {
            $path = base_path() . '/' . $dest;
            if (is_dir($path) && !is_link($path)) {
                static::removeDir($path);
                echo "Remove $dest\n";
            } elseif (is_file($path)) {
                unlink($path);
                echo "Remove $dest\n";
            }
        }
    }

    /**
     * 递归删除目录
     * @return void
     */
    protected static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? static::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
