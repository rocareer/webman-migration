<?php
/**
 * 命令注册（webman console）。
 *
 * v2：迁移配置不再落盘（无 migrate.php/migrate-pg.php）——配置由包内 PhinxConfig
 * 按环境变量确定性生成到 runtime/plugin/webman-migration/，宿主无需维护迁移配置文件。
 */
return [
    Rocareer\WebmanMigration\command\MigrateRun::class,
    Rocareer\WebmanMigration\command\MigratePgsql::class,
    Rocareer\WebmanMigration\command\MigrateAll::class,
    Rocareer\WebmanMigration\command\MigrateStatus::class,
];
