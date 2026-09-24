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
    /** 接线配置安装清单（相对项目根路径 => 投放时 md5；卸载判据的唯一依据，见 syncManifest） */
    protected const INSTALL_MANIFEST = 'runtime/rocareer-webman-migration-install-manifest.json';

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
        static::installByRelation($isFirst);
        static::retireLegacyConfigs();
    }

    /**
     * 更新：刷新接线配置 + 旧配置退役（升级专属钩子，官方 Plugin::update 调用）
     * @return void
     */
    public static function update(): void
    {
        static::installByRelation(false);
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
     * 拷贝接线配置到宿主项目。
     *
     * 落盘口径（install-standard §三）：**目标目录已存在即只补缺失文件，不看 `$isFirst`**。
     * 旧实现是官方模板的目录拷贝（overwrite=true，标准 §三/§五 明令禁止的语法），且
     * 「插件配置以包内为准」的覆盖语义会静默抹掉宿主定制——与 2026-09-24 全仓守卫改造同批纠正。
     *
     * @param bool $isFirst 首次安装标记（保留以对齐标准签名；落盘不看它，见上）
     */
    public static function installByRelation(bool $isFirst = true): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            $sourcePath = __DIR__ . "/$source";
            $destPath = base_path() . "/$dest";
            if (!is_dir($sourcePath)) {
                continue;   // 包内无该接线目录：本关系为空操作（不打印、不建目录）
            }
            if ($pos = strrpos($dest, '/')) {
                $parentDir = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0777, true);
                }
            }
            if (!is_dir($destPath)) {
                static::copyDir($sourcePath, $destPath);
                echo "Create $dest\n";
            } else {
                $copied = static::copyMissingFiles($sourcePath, $destPath);
                if ($copied > 0) {
                    echo "Create $dest ({$copied} new file(s))\n";
                }
            }
        }
        static::syncManifest();
    }

    /** 递归拷贝整目录（仅首次安装、目标不存在时用） */
    protected static function copyDir(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $targetPath = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
                continue;
            }
            if (!is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0755, true);
            }
            copy($item->getPathname(), $targetPath);
        }
    }

    /** 补齐源目录中存在而目标缺失的文件（升级路径；返回补拷数量）。缺失才写，已存在一律不覆盖（宿主定制优先）。 */
    protected static function copyMissingFiles(string $source, string $dest): int
    {
        $copied = 0;
        if (!is_dir($source)) {
            return $copied;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }
            if (!is_file($target)) {
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                copy($item->getPathname(), $target);
                $copied++;
            }
        }
        return $copied;
    }

    /**
     * 卸载接线关系：**逐文件**按安装清单判定，绝不整目录删（守卫口径见 syncManifest 上方注释）。
     */
    public static function uninstallByRelation(): void
    {
        $manifest = static::readManifest();
        foreach (array_reverse(array_values(static::$pathRelation)) as $dest) {
            $path = base_path() . '/' . $dest;
            if (is_dir($path) && !is_link($path)) {
                static::removeConfigDir($dest, $path, $manifest);
            } elseif (is_file($path)) {
                if (($manifest[$dest] ?? null) === md5_file($path)) {
                    unlink($path);
                    unset($manifest[$dest]);
                    echo "Remove $dest\n";
                } else {
                    echo "Skip remove $dest (modified by project, kept)\n";
                }
            }
        }
        static::saveManifest($manifest);
    }

    // ==================== 接线配置卸载守卫（2026-09-24 立；与 rocareer/queue v1.8.7 同源实现）====================
    // 病灶：uninstallByRelation 原为「整目录删」——宿主在 config/plugin/rocareer/<包>/ 里的定制
    // 随包资产一起消失，紧随的 install 见目录不存在又全量铺包默认 ⇒ 宿主策略静默丢失
    // （2026-09-24 queue 包实测事故：19 条队列塌进一个组、LLM 契约闸拒绝 2454 条作业）。
    // 口径（Rocareer docs/install-standard.md §四.3「必须清单精确卸载，绝不整目录删」）：
    //   安装写清单（相对项目根路径 => 投放时 md5，只登记与包内逐字节一致的文件）；
    //   卸载逐文件判 md5——宿主分叉与宿主自有文件一律保留并点名；只回收空目录；无清单则整目录按「宿主拥有」处理。

    /**
     * 写安装清单：按 pathRelation 的目标段重建条目（先清该段旧条目，再加当前一致项）。
     *
     * 只登记「与包内逐字节一致」的文件 ⇒ 宿主分叉与宿主自有文件天然不入单（卸载时保留，保守方向）。
     */
    protected static function syncManifest(): void
    {
        $manifest = static::readManifest();
        foreach (static::$pathRelation as $source => $dest) {
            $prefix = $dest . '/';
            foreach (array_keys($manifest) as $rel) {
                if ($rel === $dest || str_starts_with($rel, $prefix)) {
                    unset($manifest[$rel]);
                }
            }
            // 注意：本包的接线配置随源码落 `src/config/...`（与其它包「配置在包根」不同），
            // 故基准是 __DIR__（Install.php 所在目录）——必须与 installByRelation 的取源基准一致，
            // 否则清单会写空（2026-09-24 实测：用 dirname(__DIR__) 时清单 0 条，卸载退化成"全部保留"）。
            $sourcePath = __DIR__ . '/' . $source;
            $destPath = base_path() . '/' . $dest;
            if (is_dir($sourcePath) && is_dir($destPath)) {
                foreach (static::filesUnder($sourcePath) as $rel) {
                    $destFile = $destPath . '/' . $rel;
                    if (!is_file($destFile)) {
                        continue;
                    }
                    $md5 = md5_file($destFile);
                    if ($md5 !== false && $md5 === md5_file($sourcePath . '/' . $rel)) {
                        $manifest[$prefix . $rel] = $md5;
                    }
                }
            } elseif (is_file($sourcePath) && is_file($destPath)) {
                $md5 = md5_file($destPath);
                if ($md5 !== false && $md5 === md5_file($sourcePath)) {
                    $manifest[$dest] = $md5;
                }
            }
        }
        static::saveManifest($manifest);
    }

    /** @return array<string,string> 相对项目根路径 => 投放时 md5（无清单/损坏 ⇒ 空数组，调用方按「宿主拥有」处理） */
    protected static function readManifest(): array
    {
        $file = base_path() . '/' . static::INSTALL_MANIFEST;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $rel => $md5) {
            // 安全：仅接受项目根下的普通相对路径
            if (is_string($rel) && is_string($md5) && $rel !== ''
                && !str_starts_with($rel, '/') && !str_contains($rel, '..')) {
                $out[$rel] = $md5;
            }
        }
        return $out;
    }

    /** @param array<string,string> $manifest */
    protected static function saveManifest(array $manifest): void
    {
        $file = base_path() . '/' . static::INSTALL_MANIFEST;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        ksort($manifest);
        file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** @return list<string> 目录下所有文件的相对路径（不含目录项） */
    protected static function filesUnder(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                $out[] = $iterator->getSubPathName();
            }
        }
        return $out;
    }

    /** 自底向上回收空目录（只 rmdir 空壳；非空即停，绝不递归删内容） */
    protected static function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink() && static::isDirEmpty($item->getPathname())) {
                rmdir($item->getPathname());
            }
        }
        if (static::isDirEmpty($dir)) {
            rmdir($dir);
        }
    }

    /** 目录是否为空（不含 `.` / `..`） */
    protected static function isDirEmpty(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * 按清单精确移除一个接线配置目录（只删包投放且未被宿主改动的文件）。
     *
     * @param array<string,string> $manifest 全量清单（按引用更新：已删条目出单）
     */
    protected static function removeConfigDir(string $dest, string $path, array &$manifest): void
    {
        $prefix = $dest . '/';
        $entries = [];
        foreach ($manifest as $rel => $md5) {
            if (str_starts_with($rel, $prefix)) {
                $entries[substr($rel, strlen($prefix))] = $md5;
            }
        }
        if ($entries === []) {
            // 无清单条目：整目录按「宿主拥有」处理（首装早于本版 / 清单被清 / 目录全是宿主自建文件）
            echo "Skip remove $dest (no install manifest entry, keep existing files)\n";
            return;
        }
        $removed = 0;
        $kept = [];
        foreach ($entries as $rel => $md5) {
            $file = $path . '/' . $rel;
            if (!is_file($file)) {
                unset($manifest[$prefix . $rel]);
                continue;
            }
            if (md5_file($file) === $md5) {
                unlink($file);
                unset($manifest[$prefix . $rel]);
                $removed++;
            } else {
                $kept[] = $rel;   // 宿主分叉：留在原处，且出单（下次卸载不再拿旧哈希误判）
            }
        }
        // 未入清单的宿主自有文件一律保留，点名便于运维对账
        $hostOwned = array_values(array_diff(static::filesUnder($path), array_keys($entries)));
        static::pruneEmptyDirs($path);
        echo "Remove $dest ({$removed} file(s))\n";
        if ($kept !== []) {
            echo "Skip remove $dest (modified by project, kept): " . implode(', ', $kept) . "\n";
        }
        if ($hostOwned !== []) {
            echo "Skip remove $dest (host-owned, kept): " . implode(', ', $hostOwned) . "\n";
        }
    }
}
