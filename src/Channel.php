<?php

namespace Rocareer\WebmanMigration;

/**
 * 迁移通道：一个通道 = 一套迁移目录 + 一个目标数据库（MySQL | PostgreSQL）。
 *
 * - MySQL 通道（migrate:run）：跑 database/migrations（连接走 MYSQL_* 环境键）
 * - PostgreSQL 通道（migrate:pg）：跑 database/pg-migrations（默认，向量体系）；
 *   终局跑全量时用 `PG_MIGRATION_SETS=all`（或 --set=business|all）一并纳入 database/migrations
 *   —— 方案 A（PG 单库终局、MySQL 退役）下业务表迁移 + 向量迁移共库共 phinxlog 一次跑通
 *
 * 表前缀口径与 radmin 的 getDbPrefix()/getPgPrefix() 完全一致（PG 优先 PG_PREFIX，
 * 回退 MYSQL_PREFIX，最终缺省 ra_）；Phinx 迁移记录表为 <prefix>migrations，与 v1 保持一致
 * —— 旧部署的历史执行记录持续有效，升级 v2 不会重跑已执行迁移。
 *
 * 本包只负责「迁移执行自动发现」，不替迁移代码加前缀：迁移里一律显式
 * getDbPrefix()/getPgPrefix()（工作区惯例，避免 phinx 自动前缀与显式前缀叠加成双前缀）。
 */
final class Channel
{
    public const MYSQL = 'mysql';
    public const PG = 'pg';

    /** PG 通道迁移集合：vector（默认，仅 pg-migrations）/ business（仅 migrations）/ all（两者） */
    public const PG_SET_VECTOR = 'vector';
    public const PG_SET_BUSINESS = 'business';
    public const PG_SET_ALL = 'all';

    /** PG_MIGRATION_SETS 环境键（缺省 vector；终局 PG 单库设 all） */
    public const PG_SET_ENV = 'PG_MIGRATION_SETS';

    private function __construct(private string $name, private ?string $pgSet = null)
    {
        if ($this->name === self::PG) {
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
    }

    /** MySQL 通道：radmin 核心业务库 + 各包传统 MySQL 迁移（过渡期仍在用） */
    public static function mysql(): self
    {
        return new self(self::MYSQL);
    }

    /**
     * PostgreSQL 通道：pgvector 向量体系（memory/knowledge 建表/索引/存量搬移等）。
     *
     * @param string|null $pgSet 覆盖 PG_MIGRATION_SETS：vector|business|all，null=按环境键
     */
    public static function pg(?string $pgSet = null): self
    {
        return new self(self::PG, $pgSet);
    }

    /** @return self[] 全部通道（migrate:all / migrate:status 执行顺序：MySQL → PG） */
    public static function all(): array
    {
        return [self::mysql(), self::pg()];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->name === self::PG ? 'PostgreSQL' : 'MySQL';
    }

    public function adapter(): string
    {
        return $this->name === self::PG ? 'pgsql' : 'mysql';
    }

    /** database/ 下的迁移子目录集合（PG 通道按集合配置合并；MySQL 固定 migrations） */
    public function migrationDirs(): array
    {
        if ($this->name === self::MYSQL) {
            return ['migrations'];
        }
        return match ($this->pgSet) {
            self::PG_SET_BUSINESS => ['migrations'],
            self::PG_SET_ALL => ['migrations', 'pg-migrations'],
            default => ['pg-migrations'],
        };
    }

    /** PG 通道集合的中文说明（用于命令输出；MySQL 返回空） */
    public function pgSetLabel(): string
    {
        if ($this->name === self::MYSQL) {
            return '';
        }
        return $this->pgSet === self::PG_SET_ALL
            ? '业务+向量（全量）'
            : ($this->pgSet === self::PG_SET_BUSINESS ? '业务' : '向量');
    }

    /** 表前缀（与 radmin helpers 口径一致）：PG 优先 PG_PREFIX，最终缺省 ra_ */
    public function tablePrefix(): string
    {
        $prefix = $this->name === self::PG
            ? (self::env('PG_PREFIX', '') ?: self::env('MYSQL_PREFIX', '') ?: 'ra_')
            : (self::env('MYSQL_PREFIX', '') ?: 'ra_');
        return $prefix;
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
        if ($this->name === self::PG) {
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
        return [
            'adapter' => 'mysql',
            'host'    => self::env('MYSQL_HOSTNAME', '127.0.0.1'),
            'port'    => self::env('MYSQL_HOSTPORT', '3306'),
            'name'    => self::env('MYSQL_DATABASE', 'radmin'),
            'user'    => self::env('MYSQL_USERNAME', 'root'),
            'pass'    => self::env('MYSQL_PASSWORD', '123456'),
            'charset' => self::env('MYSQL_CHARSET', 'utf8mb4'),
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
