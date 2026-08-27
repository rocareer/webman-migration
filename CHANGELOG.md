# Changelog

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
