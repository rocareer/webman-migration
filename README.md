# rocareer/webman-migration

Webman 迁移工具插件：将 [robmorgan/phinx](https://github.com/cakephp/phinx)（^0.16）封装为 webman 插件命令 `migrate:run`，统一跑**项目自身迁移 + 全家桶各包迁移**（装任意 rocareer 插件即自动纳入，无需逐个配置路径）。

## 能力

- **一条命令跑全部迁移**：`php webman migrate:run` 依次执行项目 `database/migrations` 与 `vendor/rocareer/*/database/migrations` 两个来源的迁移
- **动态扫描包迁移**：migrate.php 配置中 vendor 路径用 `glob()` 动态收集，安装新的全家桶插件后无需改配置即可被识别
- **表前缀统一**：迁移与 think-orm 共用同一表前缀（默认 `ra_`，env `MYSQL_PREFIX` 可调），Phinx 记录表 `phinxlog` 同步加前缀
- **包级迁移基类**：`PrefixedTableMigration`（自动加前缀）、`AbstractMigration`（Phinx 基类代理）、`Table`（Phinx Table 代理）、`MigrationInterface`（Phinx 迁移接口）
- **幂等约定**：各包迁移重复执行必须安全（`hasTable`/`hasColumn`/`hasIndex` 检查 + 重复执行安全 UPDATE/INSERT），见 ai 包迁移示例

## 安装

```bash
composer require rocareer/webman-migration
php webman plugin:install rocareer/webman-migration
# 复制产物：config/plugin/rocareer/webman-migration/（app.php + command.php + migrate.php）
```

dev 全家桶（`dev/full`、`dev/luoling`）已通过 path 仓库钉版 `1.0.4` 接入。

## 使用

```bash
# 跑全部未执行迁移（项目 + 所有 rocareer 包）
php webman migrate:run

# 指定 Phinx 配置文件（默认 config/plugin/rocareer/webman-migration/migrate.php）
php webman migrate:run --config /path/to/phinx.php

# 只跑到指定版本
php webman migrate:run --target 20260909000000
```

命令内部通过 `Symfony Console` ArrayInput 驱动 PhinxApplication，执行前会把 think-orm `connections.mysql.prefix` 写入 migrate.php 模板，保证建表前缀与 ORM 一致。

## 写包迁移（约定）

各包带自己的 `database/migrations` 目录（文件名 `YYYYMMDDHHMMSS_描述.php`），模板以 ai 包为先例：

```php
<?php
/**
 * rocareer/ai —— 用途（一句话说清本次建表/改结构的原因，含幂等性说明）
 *
 * 幂等：hasColumn/hasIndex 检查，重复执行安全。
 */

use Phinx\Migration\AbstractMigration;

class RadminXxxExample extends AbstractMigration
{
    public function up()
    {
        $prefix = getDbPrefix(); // radmin helper：env('MYSQL_PREFIX') ?? think-orm 连接 prefix
        $table = $prefix . 'admin_rule';

        // 幂等守卫：建表前先查、补列/补索引前先 hasColumn/hasIndex
        if ($this->hasTable($table)) {
            return;
        }
        $this->table($table)->addColumn(...)->create();
    }

    public function down()
    {
        $this->table(getDbPrefix() . 'admin_rule')->drop();
    }
}
```

要点：
- **幂等**（铁律）：CREATE 前 `hasTable`、ALTER 前 `hasColumn`/`hasIndex`，数据修复用 WHERE 守卫或 INSERT ... ON DUPLICATE KEY；「初始迁移未建表时跳过」是标准做法
- **前缀**：一律 `getDbPrefix() . '表名'`（或继承 `PrefixedTableMigration` 自动加前缀），禁止硬编码 `ra_`
- **命名**：类名 `Radmin` 前缀 + 包标识（如 `RadminAiXxx`），文件名 `<时间戳>_<包>_<描述>.php`
- **提交**：迁移随源码入库，宿主 `php webman migrate:run` 执行；改表结构必须先跑迁移验证再收口

## 配置（config/plugin/rocareer/webman-migration/migrate.php）

由插件安装时写入，关键字段：

| 字段 | 说明 |
|---|---|
| `paths.migrations` | 迁移目录数组：项目 `database/migrations` + `vendor/rocareer/*/database/migrations` 动态 glob |
| `paths.seeds` | 种子目录（项目 `database/seeds`） |
| `table_prefix` | 表前缀，env `MYSQL_PREFIX`（默认 `ra_`） |
| `environments.dev` | mysql 连接（host/port/name/user/pass/charset，均读 `MYSQL_*` env，回退默认值） |
| `environments.dev.prefix` | Phinx 建表前缀（同 table_prefix） |

## 类参考

| 类 | 说明 |
|---|---|
| `Rocareer\WebmanMigration\command\MigrateRun` | `migrate:run` 命令（Symfony Console Command，驱动 PhinxApplication） |
| `Rocareer\WebmanMigration\Install` | webman 插件安装/卸载（复制/移除 `config/plugin/rocareer/webman-migration`） |

## 卸载

```bash
php webman plugin:uninstall rocareer/webman-migration
composer remove rocareer/webman-migration
```

仅移除 `config/plugin/rocareer/webman-migration/`；已建的表与 phinxlog 记录**不删除**（数据归项目所有）。
