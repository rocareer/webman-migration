# Changelog

## [v1.1.0] - 2026-12-06

### PostgreSQL 迁移通道（feat，配合 rocareer/memory|knowledge v3 全 PG 向量体系）

背景：memory/knowledge 向量体系整体迁移到 PostgreSQL + pgvector（ANN 检索，去 MySQL JSON 暴力余弦 + scan_limit 截断）。需要一条与 MySQL 迁移（migrate.php / migrate:run）并行的 PG 迁移通道，跑各包的 `database/pg-migrations`（建表/建 HNSW 索引/幂等搬移存量 MySQL 向量数据）。

- 新增配置 `config/plugin/rocareer/webman-migration/migrate-pg.php`：Phinx pgsql 环境（adapter=pgsql），
  动态扫描项目 `database/pg-migrations` + `vendor/rocareer/*/database/pg-migrations`（与 MySQL 的
  database/migrations 分离，互不干扰）；连接走 PG_* 环境键（PG_HOSTNAME/PG_HOSTPORT/PG_USERNAME/
  PG_PASSWORD/PG_DATABASE/PG_SCHEMA），PG_PREFIX 缺省复用 MYSQL_PREFIX（统一 ra_），默认迁移表
  `<prefix>migrations`。
- 新增命令 `php webman migrate:pg`（Rocareer\WebmanMigration\command\MigratePgsql）：驱动 Phinx 跑
  migrate-pg.php；支持 -c/--config 与 -t/--target（与 migrate:run 同款参数）。
- 说明：PG 库需先建好并启用 pgvector（`CREATE EXTENSION vector`），见 dev/scripts/pg-provision.sh 与
  docs/docker-infra.md；PG 迁移内可用 Phinx `$this->execute()` 直连 PG，需要跨库搬移 MySQL 存量数据时
  用 PDO 直连（env 键）读取 MySQL、经 Phinx 连接写入 PG，全程幂等。

## 未发布（Unreleased）


### 修复

- 删除全工作区零使用的迁移基类层（AbstractMigration/PrefixedTableMigration/MigrationInterface 共 479 行）——真实迁移一律 use Phinx 基类 + getDbPrefix()，README 类参考表同步清理。
- MigrateRun::setTablePrefix 补 : void 返回类型 + 类头中文注释。
- Install 安装方法补 : void 返回类型。
- composer.json 补 php >=8.1.0 版本约束。
- MigrateRun::setTablePrefix 幂等化：env（MYSQL_PREFIX）给出的前缀与 think-orm 配置一致时不再重写 migrate.php；不一致时也仅在内容实际变化后才落盘。此前每次 migrate:run 都会 file_put_contents 触发文件 mtime 变化，webman 监控检测到 config 变更会全量 reload，打断在途队列任务（如考点生成/试题生成），任务卡在 running。

### 文档

- 补 README：migrate:run 用法、动态扫描包迁移、表前缀统一约定、包级迁移幂等写法（hasTable/hasColumn/hasIndex + getDbPrefix）、migrate.php 配置字段、类参考、卸载

### 许可与版权

- 许可证由开源协议改为 proprietary（商业/内部专有），不适用任何开源许可证；LICENSE 文件同步替换为 Rocareer 专有许可文本。
- 版权声明统一为：Copyright (c) Rocareer Team. All rights reserved.；作者：albert@rocareer.com。
