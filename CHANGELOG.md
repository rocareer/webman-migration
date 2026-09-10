# Changelog

## [v2.4.1] - 2026-09-10

### 修复：status/all 缺省集合对齐 migrate:run 全量口径

`migrate:status` / `migrate:all` 的 `--set` 缺省原跟随 `PG_MIGRATION_SETS` 环境键
（缺省 `vector`），与 `migrate:run` 的全量口径不一致，产生两个假象：

- **status 只扫 vector 集误报业务集 MISSING**：业务集的 phinxlog 历史行被报
  「已记录但文件缺失」（退出码 2 假红），部署脚本按退出码判活即误报；
- **migrate:all「部署首选」缺省欠扫**：缺省只跑向量集，与自身文档「全量」表述矛盾，
  业务集迁移被静默跳过。

v2.4.1 起两命令 `--set` 缺省一律对齐 `migrate:run`（`all` 全量集），显式传
`--set=vector|business` 不受影响。

## [v2.4.0] - 2026-09-09

### 新增：运行前迁移文件强检扩展——命名精确到秒，非法文件拦截不放行（v2.3.0 只查撞号）

背景：老板拍板「新建迁移文件务必精确到秒，禁止年月日就完了」。Phinx 文件名正则
`/^\d+_名字\.php$/` 对前缀位数完全不设防——8 位纯日期文件（如 `20260906_xxx.php`）会
照常加载且前缀即版本号，同日多文件/跨包撞号高危；不匹配正则的文件则被 Phinx **静默
忽略**，永远不执行，造成「已迁移」假象。v2.3.0 预检只收 `^\d{14}_` 文件查撞号，对这些
形态视而不见（盲区）。

- **运行前强检扩展（migrate:run / pg / all / status 四命令全覆盖）**，以下任一命中直接
  FAILURE 中止并输出文件清单与修复指引：
  - 版本号撞车（v2.3.0 已有）；
  - **数字前缀 ≠ 14 位时间戳**（含 8 位纯日期风——违反「精确到秒」，撞号高危）；
  - **Phinx 静默忽略文件**（名字段非法/无数字前缀，永不执行，比报错更危险）；
  - **14 位裸版本号缺名字段**（`^\d{14}\.php$`，Phinx 会加载但类名推导脆弱）；
  - **迁移类名重复**（版本号唯一但 class 同名照样 PHP fatal，migrate:create 有查、运行时此前没有）；
- **「年月日+000000」存量警告放行**：一刀切拦截会让新环境全量待执行时无法初始化；
  已执行的不受影响，未执行的建议 `migrate:create` 重建（up() 幂等，换号重跑安全跳过）
  + `migrate:prune --apply` 清旧记录；
- **migrate:create 防签发 000000**：真实时间恰为整点零分零秒时自动顺延 +1 秒，签发的
  版本永不落入警告口径（工作区现存 44 个 `000000` 存量，新建从源头杜绝）。

## [v2.3.0] - 2026-09-09

### 新增：migrate:create 创建迁移（真实时间戳 + 全局查重）+ 运行前撞号强制预检

背景：迁移版本号 = 14 位时间戳，手编「日期 + 整点 000000」风格让分秒位全部浪费
（工作区 205 个迁移里 44 个 `000000` 后缀），多包同日建迁移频繁撞号——Phinx 加载阶段
撞号直接抛 Duplicate migration 异常，全部迁移（含无关包）都跑不了，排查靠人工 find 全局查重。

- **`migrate:create <name>`**：版本号用创建那一刻的真实时间（时分秒用满）；写文件前对
  全量集合（项目 + 全部 `vendor/rocareer/*` × `migrations`/`pg-migrations`，两集合共享
  Phinx 版本命名空间）查重，撞号自动 +1 秒顺延；顺带查类名重复（防 PHP fatal）；
  产出幂等迁移骨架（中文注释 + up/down）。`--dir=pg-migrations` 选向量集合，
  `--pkg=<包名>` 直接落到包源码（dev 宿主 vendor 为 symlink）；
- **运行前撞号强制预检**：`BaseMigrateCommand::runChannel()` 起步即扫本次通道装载目录，
  版本号重复直接 FAILURE 中止，输出撞号文件完整路径与修复指引（改未发布迁移号 /
  `migrate:prune --apply` 清旧记录 / 以后用 `migrate:create`），替代 Phinx 晦涩异常；
  migrate:run / migrate:pg / migrate:all / migrate:status 四命令全覆盖。

## [v2.2.0] - 2026-09-09

### 新增：migrate:prune —— 清理「已记录但文件缺失」的历史迁移记录

- 迁移文件改名/下架后记录表残留旧版本行（migrate:status 退出码 2 的 missing 项）；
  `php webman migrate:prune` 对照 PG 全量集合全部迁移目录列出缺失记录，
  `--apply` 实际删除（缺省 dry-run）；只动记录表不碰业务表；
- 退出码对齐 status 语义：0=无缺失/已清理，1=运行错误，2=存在缺失未 --apply。

## [v2.1.1] - 2026-08-31

### Install.php 标准化（docs/install-standard.md）

- 补 `update()` 钩子（升级刷新接线配置 + 旧配置退役，官方 Plugin::update 直接调用）；
  `install($isFirst = true)` 签名兼容官方传参。
- uninstallByRelation 去官方 remove_dir() 骨架残留，自实现递归删除；
  copy_dir(..., true) 保留（接线配置以包内为准刷新，注释已声明）。
- rocareer:audit install_standard 规则全绿。

## [v2.1.0] - 2026-08-29

### PG-only 通道收口：移除 MySQL 通道（方案 A 已全 PG，MySQL 退役）
- 删除 Channel::mysql()/MYSQL 常量与 MYSQL_* 连接分支；连接/前缀仅 PG（PG_PREFIX 缺省 ra_）
- migrate:run 语义 = PG 通道全量（业务+向量，等价 migrate:pg --set=all），历史命令名不变、README/dev 流程无缝
- migrate:all = PG 全量（fail-fast）；migrate:status --channel 仅接受 pg


## [v2.0.0] - 2026-08-29

### 重构：配置即代码 + 通道化引擎 + 退出码真实化（方案 A：专为 webman PG 而生）

背景：v1.1.0 的 MySQL/PG 双通道靠「两份宿主配置文件 + 两个几乎重复的命令」支撑，已经累积了
多处可靠性钉子（migrate.php 的 getenv 双参误用、动态扫描与宿主旧配置漂移、运行期 str_replace
改写配置触发 webman 监控 reload、Phinx 退出码被吞掉恒返回 0）。本次按「简化 + 可靠 + 强大」
重做，定位为方案 A（PG 单库终局、MySQL 退役）的迁移基础设施：

- **配置即代码（核心）**：删除两份宿主导入模板（migrate.php / migrate-pg.php）。Phinx 配置由
  `PhinxConfig` 按环境变量**确定性生成**到 `runtime/plugin/webman-migration/migrate-<通道>.php`；
  内容未变化不落盘（避免 mtime → webman 监控 reload 打断在途队列任务）、临时文件 + rename
  原子写入。彻底消灭「安装覆盖丢失 / 双份漂移 / 运行期改写宿主配置」三类问题。
- **通道化引擎**：新增 `Channel`（MySQL/PG 值对象：目录/前缀/连接/集合）+ `BaseMigrateCommand`
  （统一配置解析、参数透传、退出码透传），MigrateRun/MigratePgsql 收敛为薄壳，消除重复代码。
- **退出码真实化（关键修复）**：Phinx 退出码原样透传 webman CLI——v1 恒返回 0，迁移失败
  对 CI/部署脚本不可见（实测 Phinx 报 The "name" argument does not exist 也静默通过）。
- **PG 迁移集合**：`migrate:pg --set=vector|business|all`（或 env `PG_MIGRATION_SETS`，默认
  vector）——终局形态 `all` 时业务表迁移（database/migrations）与向量迁移（pg-migrations）
  同库共 phinxlog 一次跑通；过渡期默认 vector 不改变 dev 现有行为。
- **新命令**：`migrate:all`（MySQL → PG 两通道，fail-fast，部署首选）、`migrate:status`
  （两通道状态一览，`--channel` 单通道、`--json` 机器可读；退出码透传 phinx status 语义
  ——0 全部已执行 / 2 存在记录缺文件的历史版本（安全）/ 3 存在未执行迁移）。
- **修复**：getenv 双参误用全链修复（`getenv($k, 'ra_')` 第二参数是 local_only 非默认值，
  MySQL 侧此前同样命中）；迁移目录仅收录存在目录（项目无 database/migrations 也能跑）；
  `PG_HOST` 别名移除，统一 `PG_HOSTNAME`；调试打印（`Phinx Input: ...`）移除；
  AsCommand 属性替代 `$defaultName`（symfony console 7/8）；`--name` 参数移除
  （Phinx migrate 无此参数，原生必抛错）。
- **安装钩子**：只落盘 `app.php`/`command.php` 且 `copy_dir(..., overwrite=true)`——
  升级以包为准覆盖刷新（webman copy_dir 默认「存在即跳过」，v1 曾致宿主 command.php 长期
  停留在旧版命令清单）；检测到 v1 旧迁移配置自动改名 `.bak` 保留。
- **兼容性**：命令名不变（migrate:run / migrate:pg 语义不变），迁移记录表 `<前缀>migrations`
  不变——升级不重跑已执行迁移；radmin 依赖约束放宽为 `^1.0.3 || ^2.0`。

## [v1.1.0] - 2026-08-29

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
