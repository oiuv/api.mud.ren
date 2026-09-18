# 升级验证记录

## 升级前基线

- 起点：分支 `mudren`，提交 `5a3de6b`，开始升级前业务工作区干净。
- Herd PHP 7.4.33，Composer 2.10.2；按原 lock 安装 160 个依赖。
- 命令（使用 Herd PHP 7.4 执行）：`php vendor/bin/phpunit --log-junit storage/logs/phpunit-upgrade-baseline.xml`。
- 结果：37 tests / 331 assertions，0 errors / failures / skipped。
- 首次测试缺少 Passport 密钥；确认本地不存在密钥后执行 `php artisan passport:keys --no-interaction`。随后全绿。密钥与测试报告位于已忽略目录，未纳入 Git。
- 测试使用内存 SQLite、跳过本地环境和生产配置缓存，并隔离队列、邮件、CAPTCHA 和 Elasticsearch；未访问或修改现网数据。
- 已确认 Herd PHP 8.4.25 与 PHP 8.5.10 可执行，后续用于目标版本验证。

## 消费者契约

- 前端 `src/modules/search/SearchPage.vue` 及导航搜索：`GET /threads/search?q=...&page=...`，`data/links/meta` 与 `highlights.title/content`。
- 前端 `src/modules/auth/services.js`：`POST /oauth/token` 密码授权，客户端 ID/secret，`GET /me` 及令牌撤销。
- RAG `scripts/sync_data.py`：按历史主题 ID 读取关联 `contents`，多态类型为 `App\Thread`；`app/main.py` 请求 `/threads/{id}`，需要 `content.markdown`，跳过 404/410，不允许恢复共享 API 限额。

## 已识别的依赖风险

- Scout / Elasticsearch 客户端及旧 ES 适配：整体移除。
- Passport 7 → 当前受支持版本：密码授权显式启用、客户端结构和个人访问令牌行为需适配。
- 旧阿里云邮件基于 SwiftMailer，旧 uploader 限定旧 Illuminate：需要升级或替换。用户确认未使用七牛，其依赖直接移除。
- PHPUnit 8 和旧 factory API、异常签名、日期转换、同步任务辅助函数、中间件与资源类需要适配新框架。

以下按阶段记录真实命令和结果；外部环境验收另行列出，不作为自动化测试结论。

## 数据库搜索阶段

- 命令：Herd PHP 7.4 执行 `vendor/bin/phpunit --stop-on-error --log-junit storage/logs/phpunit-database-search.xml`。
- 结果：44 tests / 408 assertions，全通过。保留原有 37 个测试，新增 7 个数据库搜索测试。
- 覆盖中文/单字、HTML 正文、备用 query 参数、字面通配符、SQL 字面输入、空/非法查询、分页排序、安全高亮、即时编辑、软删除正文、未激活作者和评论排除。
- 旧 ES 命令注册遗漏导致首次启动失败，补齐移除后完整回归通过。模型保存只保留事务后的提及任务，不再派发索引任务。
- Composer 中搜索依赖随下一阶段统一解析移除；此阶段先在已安装的原依赖上验证业务行为。

## 框架与依赖结果

已安装并锁定：Laravel 13.32.0、Passport 13.8.0、PHPUnit 12.5.35、Symfony Mailer 7.4.19、uploader 3.4.0、avatar 7.0.0、activitylog 4.12.3、JoyPixels 11.0.0。Composer platform 为 PHP 8.3.0，最低运行要求 PHP 8.3。

- 移除 Scout、Elasticsearch/Tamayo、七牛、Socialite、旧 SwiftMailer 阿里云邮件适配、旧代理/CORS 适配及旧 emoji Laravel 包。
- 使用原生代理/CORS 中间件、Symfony Mailer 与项目内 DirectMail transport、JoyPixels；保留 SMTP、邮件模板、本地上传与头像。
- Passport 显式启用密码授权、保留整数客户端 ID；迁移旧 client secret 哈希及 grant_types，原客户端 secret 仍可登录。POST/DELETE 撤销检查令牌归属，并撤销关联 refresh token。
- 删除第三方登录路由/控制器，保留 profiles 历史表；前端 login.vue 的社交登录按钮原本已注释，未修改前端或 RAG 仓库。前端 services.js 声明 POST 撤销接口，但当前 logout action 仅清除本地令牌；本次提供对应后端撤销能力，没有改变前端退出流程。
- 保留 follow 1.1.16 的历史关系模型。ParsedownExtra 0.8.1 配合 Parsedown 1.7.4；实测 Parsedown 1.8 会使旧标题解析出现 `Undefined array key text`，因此约束 1.7.x，未采用预发布版 Extra。
- PHPUnit 使用类工厂，测试启动采用内存 SQLite、临时 RSA 密钥、array mail、队列和 CAPTCHA 替身；缓存命令的二次启动同样跳过本地 .env。
- 格式配置迁移到 `.php-cs-fixer.php`，只格式化本次修改文件。原 Travis 配置更新为 PHP 8.3/8.4/8.5 回归；未运行远程 CI。

## 最终回归与启动检查

Herd 可执行文件为 `C:\Users\oiuv\.config\herd\bin\php83|php84|php85\php.exe`；Composer 使用 Herd 的 `composer.phar`，不依赖默认 php.bat。

| 环境 | 完整 PHPUnit | Composer 平台检查 | 配置/路由缓存及缓存启动 |
| --- | --- | --- | --- |
| PHP 8.3.33 | 58 tests / 497 assertions | 全通过 | 86 routes，PASS |
| PHP 8.4.25 | 58 tests / 497 assertions | 全通过 | 86 routes，PASS |
| PHP 8.5.10 | 58 tests / 497 assertions | 全通过 | 86 routes，PASS |

实际命令：`php vendor/bin/phpunit --display-deprecations --log-junit storage/logs/phpunit-laravel13-review-phpXX.xml`、`php composer.phar check-platform-reqs`、`php tests/runtime-smoke.php`。审查前的 57 tests / 492 assertions 报告仍保留，最新回归含 SMTP 加密与旧个人令牌客户端验证。XML 和控制台日志在忽略目录 `storage/logs/`。所有测试无 failures/errors/skipped；负向权限及撤销测试产生的预期异常日志不代表失败。

保留原有 37 个测试的业务覆盖，新增搜索、Passport、上传/头像、邮件、关系和 CORS 检查。ES 替身由数据库搜索断言替代，提及任务断言去掉已移除的索引派发次数。

其他通过项：

- `composer validate --strict`：有效、无警告。补齐了与 README 一致的 MIT 包元数据。
- `composer audit --locked --no-interaction`：2026-09-19 检查未发现已知安全公告。
- `composer install --dry-run --no-dev --no-scripts --no-interaction`：lock 可安装，0 installs / 0 updates；47 个 removals 仅为模拟排除开发依赖，没有实际删除。
- PHP 8.3 上对 40 个修改/新增 PHP 文件运行 CS Fixer dry-run：0 files to fix。
- `git diff --check`：通过；环境文件、Passport 密钥、测试缓存与报告未纳入 Git。
- `openspec validate modernize-forum-backend --strict`：通过。

## MySQL 5.7.40 验证

临时 Docker 官方镜像 `mysql:5.7.40`（Linux），仅映射 `127.0.0.1` 随机端口，固定数据库 `codex_forum_upgrade_test`，未读取本地论坛 .env 或连接现网。设置 `FORUM_TEST_MYSQL_PORT` 后运行 Herd PHP 8.4 的 `tests/mysql-smoke.php`。

从空库执行历史迁移，插入用户/帖子/客户端 ID 73，再执行两条升级迁移及一次重复 migrate；验证原明文 secret 登录、历史用户与 RAG 正文、中文及 `%`/`_`/`!`/反斜杠、创建/编辑后即时检索、封禁过滤、MySQL JSON 浏览计数和新个人令牌签发。最终全部 PASS。

实机检查修复两个 SQLite 未暴露的问题：

1. 旧 follow 迁移按框架版本使用 BIGINT，但 users.id 实际是 INT，导致外键失败；改为匹配的 unsigned INT，仅影响空库重建。
2. utf8mb4_unicode_ci 下 LIKE 双转义字符匹配与 SQLite 有差异；最终改为 `INSTR(LOWER(字段), LOWER(?)) > 0` 参数化字面子串检索，保留排序/分页/高亮契约，增加反斜杠回归。

临时容器已停止并自动删除。复验命令见 `docs/upgrade-laravel13.md`。

## 待发布环境验收

未执行生产部署、真实邮件发送、真实 CAPTCHA、IIS 切换或 Dify/RAG 网络调用，未导出现网数据。MySQL 使用合成历史样例，不能代表所有现网数据形态，Linux 容器也不替代 Win64/IIS 测试副本验收。

README 和升级指南包含邮件配置、独立测试库验收、密钥保留、短时维护及备份恢复步骤。client secret 哈希不可逆，回退依赖升级前数据库备份；旧版本令牌是否全部继续有效未经验证，允许重新登录。

## 提交前审查（2026-09-19）

- 修复 SMTP 配置兼容：旧 `MAIL_ENCRYPTION=tls` 现在设置 Symfony transport 的 require_tls，继续强制 TLS；`ssl` 使用隐式 TLS。新增隔离测试先复现失败，修复后通过，没有向真实 SMTP 服务器发信。行为参考 [Symfony Mailer TLS 文档](https://symfony.com/doc/current/mailer.html#ensure-tls)。
- 补充旧个人令牌客户端迁移验证：保留原客户端 ID，并使用迁移后的客户端签发可访问 `/me` 的令牌。
- 修正维护发布步骤：维护模式下，用旧 PHP/代码带 `--force --stop-when-empty` 排空指定队列，并核对延迟任务。参数核对 [Laravel 6 WorkCommand](https://github.com/laravel/framework/blob/6.x/src/Illuminate/Queue/Console/WorkCommand.php)。
- 修复后 PHP 8.3/8.4/8.5 完整回归均为 58 tests / 497 assertions；重新通过 PHP 8.3 缓存启动检查。未修改搜索或数据库迁移实现，因此沿用此前已通过的 MySQL 5.7.40 集成验证。
