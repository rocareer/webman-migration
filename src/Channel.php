<?php

namespace Rocareer\WebmanMigration;

/**
 * 迁移通道：一个通道 = 一套迁移目录 + 一个目标数据库（PostgreSQL）。
 *
 * 架构无 MySQL（PG 单库终局、MySQL 退役）：v2.0.0 的 MySQL 通道已移除，唯一通道即 PostgreSQL。
 * - migrate:run —— 历史命令名保留，语义 = PG 通道全量（业务 + 向量，等价 migrate:pg --set=all）
 * - migrate:pg  —— PG 通道（向量默认 / --set=business|all 切换）
 * - migrate:all —— 全通道（现等价 migrate:pg --set=all）
 *
 * 表前缀口径与 radmin 的 getPgPrefix() 完全一致（PG_PREFIX，缺省 ra_）；Phinx 迁移记录表
 * 为 <prefix>migrations，与 v1 保持一致——历史执行记录持续有效，升级不会重跑已执行迁移。
 *
 * 本包只负责「迁移执行自动发现」，不替迁移代码加前缀：迁移里一律显式
 * getDbPrefix()/getPgPrefix()（工作区惯例，避免 phinx 自动前缀与显式前缀叠加成双前缀）。
 */
final class Channel
{
    public const PG = 'pg';

    /** PG 通道迁移集合：vector（默认，仅 pg-migrations）/ business（仅 migrations）/ all（两者） */
    public const PG_SET_VECTOR = 'vector';
    public const PG_SET_BUSINESS = 'business';
    public const PG_SET_ALL = 'all';

    /** PG_MIGRATION_SETS 环境键（缺省 vector；终局 PG 单库设 all） */
    public const PG_SET_ENV = 'PG_MIGRATION_SETS';

    private function __construct(private string $name, private ?string $pgSet = null)
    {
        $this->pgSet = $pgSet ?? strtolower(self::env(self::PG_SET_ENV, self::PG_SET_VECTOR));
        if (!in_array($this->pgSet, [self::PG_SET_VECTOR, self::PG_SET_BUSINESS, self::PG_SET_ALL], true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s 仅支持 %s|%s|%s，实际：%s',
                self::PG_SET_ENV,
                self::PG_SET_VECTOR,
                self::PG_SET_BUSINESS,
                self::PG_SET_ALL,
                $this->pgSet
            ));
        }
    }

    /**
     * PostgreSQL 通道：pgvector 向量体系（memory/knowledge 建表/索引等）与业务表共库共 phinxlog。
     *
     * @param string|null $pgSet 覆盖 PG_MIGRATION_SETS：vector|business|all，null=按环境键
     */
    public static function pg(?string $pgSet = null): self
    {
        return new self(self::PG, $pgSet);
    }

    /** @return self[] 全部通道（现仅 PG；migrate:all / migrate:status 的兼容迭代入口） */
    public static function all(): array
    {
        return [self::pg()];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'PostgreSQL';
    }

    public function adapter(): string
    {
        return 'pgsql';
    }

    /** database/ 下的迁移子目录集合（PG 通道按集合配置合并） */
    public function migrationDirs(): array
    {
        return match ($this->pgSet) {
            self::PG_SET_BUSINESS => ['migrations'],
            self::PG_SET_ALL => ['migrations', 'pg-migrations'],
            default => ['pg-migrations'],
        };
    }

    /** PG 通道集合的中文说明（用于命令输出） */
    public function pgSetLabel(): string
    {
        return $this->pgSet === self::PG_SET_ALL
            ? '业务+向量（全量）'
            : ($this->pgSet === self::PG_SET_BUSINESS ? '业务' : '向量');
    }

    /** 表前缀（与 radmin getPgPrefix 口径一致）：PG_PREFIX，缺省 ra_ */
    public function tablePrefix(): string
    {
        return self::env('PG_PREFIX', '') ?: 'ra_';
    }

    /** Phinx 迁移记录表名：<prefix>migrations（v1 起沿用，不因版本升级丢失历史） */
    public function migrationTable(): string
    {
        return $this->tablePrefix() . 'migrations';
    }

    /**
     * 迁移目录（绝对路径）：项目 database/ 下各集合目录 + 各 rocareer 包同名目录。
     * 仅收录「存在」的目录（项目没建迁移目录时跑起来也不报错）；动态 glob，
     * 安装新插件后无需任何配置即可纳入。
     */
    public function migrationPaths(): array
    {
        $root = function_exists('base_path') ? base_path() : (string) getcwd();
        $paths = [];
        foreach ($this->migrationDirs() as $dir) {
            $paths[] = $root . '/database/' . $dir;
            foreach (glob($root . '/vendor/rocareer/*/database/' . $dir) ?: [] as $path) {
                $paths[] = $path;
            }
        }
        $paths = array_values(array_unique(array_filter($paths, 'is_dir')));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** 数据库连接参数（env 驱动；缺省值对齐 dev 各工程 .env 模板） */
    public function connection(): array
    {
        return [
            'adapter' => 'pgsql',
            'host'    => self::env('PG_HOSTNAME', '127.0.0.1'),
            'port'    => self::env('PG_HOSTPORT', '5433'),
            'name'    => self::env('PG_DATABASE', 'radmin'),
            'user'    => self::env('PG_USERNAME', 'root'),
            'pass'    => self::env('PG_PASSWORD', '123456'),
            'schema'  => self::env('PG_SCHEMA', 'public'),
        ];
    }

    /**
     * 取环境变量（getenv / $_ENV / $_SERVER 三源兜底），空串视为未设置。
     * 注意：PHP getenv() 第二参数是 local_only 不是默认值，勿写成 getenv($k, 'xx')。
     */
    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? null;
        }
        if (!$value) {
            $value = $_SERVER[$key] ?? null;
        }
        return (string) ($value ?: $default);
    }
}
