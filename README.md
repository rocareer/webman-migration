# rocareer/webman-migration

Webman 迁移基础设施插件：基于 [robmorgan/phinx](https://github.com/cakephp/phinx)（^0.16），
为 **webman + PostgreSQL**（方案 A：PG 单库终局、MySQL 退役）而生，同时兼容过渡期 MySQL 通道。

一条命令跑项目自身迁移 + 全家桶各包迁移（装任意 rocareer 插件自动纳入，无需逐个登记），
配置即代码（按环境变量确定性生成）、零宿主配置文件、运行期零改写、退出码真实可信。

## 设计（v2）

- **通道（Channel）**：`MySQL`（`database/migrations`，MYSQL_* 环境键）与 `PostgreSQL`
  （`database/pg-migrations`，PG_* 环境键）两条独立通道，共用同一引擎。
  PG 通道是头等公民：支持 schema、pgvector 迁移、迁移集合配置。
- **配置即代码**：Phinx 配置由 `PhinxConfig` 按环境变量确定性生成到
  `runtime/plugin/webman-migration/migrate-<通道>.php`（内容未变化不落盘，原子写入）。
  宿主不再有 migrate.php / migrate-pg.php 配置文件，也不会有「旧配置被覆盖 / 双份漂移 /
  运行期 str_replace 改写」类问题。
- **退出码 = 执行结果**：Phinx 退出码原样透传（v1 恒返回 0，迁移失败被吞掉）。
- **动态发现**：迁移目录 = 项目 `database/<dir>` + `vendor/rocareer/*/database/<dir>`
  （glob 通配，仅收录存在的目录），装新插件无需改任何配置。

## 命令

| 命令 | 作用 |
|---|---|
| `php webman migrate:run` | MySQL 通道迁移（业务库，过渡期主力） |
| `php webman migrate:pg` | PostgreSQL 通道迁移（向量库；`--set=all` 含业务表，终局形态） |
| `php webman migrate:all` | 全部通道：先 MySQL 后 PG，任一失败立即中止（部署首选） |
| `php webman migrate:status` | 列出各通道已执行/待执行/缺文件迁移（`--channel` 单通道，`--json` 机器可读） |

通用参数（migrate:run / migrate:pg）：

- `-c, --config <文件>`：使用自定义 phinx 配置文件（覆盖自动生成）
- `-e, --environment <名>`：目标环境（缺省用配置文件默认环境）
- `-t, --target <版本>`：只跑到指定版本；`-d, --date <YYYYMMDD>`：跑到指定日期
- `-k, --count <N>`：只执行最近 N 个待执行迁移
- `-x, --dry-run`：只输出将执行的 SQL，不落库（上线前预检）

### PG 迁移集合（--set / PG_MIGRATION_SETS）

PG 通道默认跑 **向量** 集合（`pg-migrations`，memory/knowledge 的 pgvector 体系）。
方案 A 终局（业务表也切 PG）时设 `PG_MIGRATION_SETS=all`（或 `--set=all`），
业务表迁移（`migrations`）与向量迁移同库共 phinxlog 一次跑通：

```
PG_MIGRATION_SETS=all php webman migrate:pg      # 终局：业务 + 向量
php webman migrate:pg --set=business             # 只跑业务表迁移（单次核对）
PHP webman migrate:pg --set=all -x               # 终局预检（只出 SQL）
```

| 取值 | 迁移目录 | 说明 |
|---|---|---|
| `vector`（默认） | `migrations` 之外只跑 `pg-migrations` | 过渡期现状：memory/knowledge 向量表 |
| `business` | 只跑 `migrations` | 业务表迁移单独核对时用 |
| `all` | `migrations` + `pg-migrations` | **终局形态**：业务 + 向量全量 |

### 退出码语义

| 码 | 命令 | 含义 |
|---|---|---|
| 0 | 全部命令 | 成功 |
| 1 | 全部命令 | 运行错误（连接失败 / 迁移失败 / 配置缺失） |
| 2 | `migrate:status` | 存在「已记录但迁移文件缺失/重命名」的历史版本（安全，不会重跑；常见于包卸载后其迁移仍在日志中） |
| 3 | `migrate:status` | 存在未执行迁移（待 `migrate:*` 执行） |

## 环境变量（配置即代码的唯一输入）

| 通道 | 键 | 缺省 |
|---|---|---|
| MySQL | `MYSQL_HOSTNAME` `MYSQL_HOSTPORT` `MYSQL_DATABASE` `MYSQL_USERNAME` `MYSQL_PASSWORD` `MYSQL_CHARSET` | 127.0.0.1 / 3306 / radmin / root / 123456 / utf8mb4 |
| MySQL | `MYSQL_PREFIX` | `ra_` |
| PG | `PG_HOSTNAME` `PG_HOSTPORT` `PG_DATABASE` `PG_USERNAME` `PG_PASSWORD` `PG_SCHEMA` | 127.0.0.1 / 5433 / radmin / root / 123456 / public |
| PG | `PG_PREFIX`（缺省回退 `MYSQL_PREFIX`，再回退 `ra_`） | `ra_` |
| PG | `PG_MIGRATION_SETS` | `vector` |

- 表前缀口径与 radmin 的 `getDbPrefix()`/`getPgPrefix()` 完全一致；
  迁移代码里一律显式 `getDbPrefix()`/`getPgPrefix()`（工作区惯例）
- Phinx 迁移记录表 = `<前缀>migrations`（v1 起沿用，升级不重跑已执行迁移）
- 环境变量读取覆盖 getenv / $_ENV / $_SERVER 三源，空串视为未设置

## 写包迁移（约定）

各包带 `database/migrations`（MySQL 通道）；PG 专用迁移（向量等）放
`database/pg-migrations`（PG 通道文件夹）。文件名
`YYYYMMDDHHMMSS_<包标识>_<描述>.php`（时间戳全局唯一），类名 `Radmin<包>Xxx`
（或包前缀），模板以 ai 包为先例；**幂等铁律**：CREATE 前 `hasTable`、ALTER 前
`hasColumn`/`hasIndex`，数据修复用 WHERE 守卫 / `INSERT ... ON DUPLICATE KEY UPDATE`；
**PG 事务铁律**：Phinx 在 PG 上把整个迁移包进事务，搬移逻辑必须「先校验后插入」，
失败路径唯一，禁止依赖 catch 恢复。

```php
<?php
use Phinx\Migration\AbstractMigration;

class RadminAiExample extends AbstractMigration
{
    public function up()
    {
        $table = getDbPrefix() . 'example';          // PG 通道用 getPgPrefix()
        if ($this->hasTable($table)) { return; }      // 幂等守卫
        $this->table($table)->addColumn(...)->create();
    }

    public function down()
    {
        $this->table(getPgPrefix() . 'example')->drop();
    }
}
```

## 自定义 phinx 配置

自动生成配置覆盖标准 phinx 用法；需要完全自定义时自备配置文件并用 `--config` 指定
（可放仓库或部署机），生成器不再介入。凤凰原生能力（rollback、seed、breakpoint 等）
同样通过 `--config` + phinx 直接调用：

```bash
vendor/bin/phinx rollback -c runtime/plugin/webman-migration/migrate-mysql.php   # 回滚上一个迁移
vendor/bin/phinx status -c runtime/plugin/webman-migration/migrate-pg.php
```

## 安装 / 升级 / 卸载

```bash
composer require rocareer/webman-migration
php webman plugin:install rocareer/webman-migration
```

- 安装只落盘两份接线配置 `config/plugin/rocareer/webman-migration/{app,command}.php`
  （升级以包为准覆盖刷新，杜绝宿主旧配置漂移）
- **v1 → v2 升级**：宿主旧 `migrate.php`/`migrate-pg.php` 自动改名为 `.bak` 保留
  （v2 已不读取；如无自定义可直接删除）。命令名不变，执行结果一致，迁移记录表不变。
- 卸载：`php webman plugin:uninstall rocareer/webman-migration && composer remove ...`；
  仅移除接线配置，已建表与 phinxlog 记录不删除（数据归项目所有）。

## 类参考

| 类 | 说明 |
|---|---|
| `Rocareer\WebmanMigration\Channel` | 通道值对象：MySQL/PG 的目录、前缀、连接参数、迁移集合 |
| `Rocareer\WebmanMigration\PhinxConfig` | 按通道确定性生成 phinx 配置（runtime 目录，原子写入） |
| `Rocareer\WebmanMigration\command\BaseMigrateCommand` | 命令引擎：配置解析/参数透传/退出码透传 |
| `...\command\MigrateRun/MigratePgsql/MigrateAll/MigrateStatus` | 四个命令 |
| `Rocareer\WebmanMigration\Install` | 插件安装/卸载钩子（含 v1 旧配置 .bak 过渡） |

## 兼容性

- PHP >= 8.1（`workerman/webman-framework` ^2.1、`robmorgan/phinx` ^0.16）
- 命令注册依赖 webman console（`config/plugin/.../command.php`）
- v1 的 `migrate:run --name <迁移名>` 参数已移除（Phinx migrate 无该参数，原生恒报错）
