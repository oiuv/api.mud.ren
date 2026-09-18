# 从 Laravel 6 / PHP 7.4 升级

本次保持业务表、用户/帖子 ID、`App\Thread` / `App\Comment` 多态类型及前端和 RAG 契约。目标为 Laravel 13、PHP 8.3+，可继续使用 MySQL 5.7.40。普通搜索采用 MySQL 子串查询，删除 Elasticsearch/Scout、七牛和第三方登录。

本指南中的服务器操作由维护者在发布时执行。本次没有部署现网，也没有读取或迁移现网数据库。

## 数据变化与回退边界

新增两条增量迁移：

- `2026_09_18_000001_upgrade_oauth_clients`：保留整数客户端 ID 和旧字段，补充 provider、owner、redirect_uris、grant_types，将明文 client secret 转为哈希。前端继续提交原有明文 secret，无需把数据库哈希填到前端。
- `2026_09_18_000002_upgrade_activity_log`：补充 event / batch_uuid 字段。

旧关注表迁移修正了 user_id 类型，使空库安装与 `users.id` 的 unsigned INT 一致；已执行过的迁移不会再次运行。历史第三方账号关联记录不删除。

client secret 哈希不可逆，回退必须同时恢复升级前数据库备份。保留原 `APP_KEY`、`storage/oauth-private.key`、`storage/oauth-public.key`。不要用 `migrate:rollback` 回退本次升级，不运行 `migrate:fresh`、`passport:install`、`passport:keys --force` 或重新生成 APP_KEY。上线后允许用户重新登录，不承诺 Laravel 6 签发的所有旧令牌继续有效。

## 先在服务器测试副本验收

1. 备份数据库、`.env`、Passport 密钥、`storage/app/public`、旧代码及原 lock。将备份导入单独的测试库，配置测试副本连接该库；邮件设为 log，避免向真实用户发信。
2. 使用 PHP 8.4 或 8.5 按新 lock 安装依赖，执行 `php artisan migrate --force`。服务器不使用 `composer update` 重新解析依赖。
3. 用历史账号验证密码登录、`/me`、旧正文、注册/激活、重置密码、发帖/评论/编辑/删除、点赞/订阅/收藏及图片上传。确认旧客户端 ID 和原前端 secret 可登录。
4. 搜索中文、`%`、`_`、`!`、反斜杠和正文词语，检查草稿/封禁/删除过滤及修改后即时检索。用前端检查分页、高亮、退出登录及 AI/RAG 正文读取。
5. 用维护者控制的邮箱验收真实激活/重置邮件，核验 SMTP 或 DirectMail 的凭据、发件域名和送达；结束后恢复测试副本的 log 邮件配置。自动化测试仅模拟网关，没有验证真实送达。
6. 在 IIS 对应应用池验证 PHP 版本、PDO MySQL/GD、写目录权限、上传链接和跨域头。容器中的 Linux MySQL 验证不替代现网 Win64/IIS 测试副本验收。

## 短时维护发布

以下 `php` 应指向目标 PHP 8.x；Herd 可按 README 指定完整路径。数据库名、目录和应用池以服务器实际配置为准。

1. 在旧代码/PHP 7.4 仍可运行时执行 `php artisan down`，暂停定时任务及其他任务生产者。普通 worker 在维护模式下不会继续取任务：先等正在执行的任务结束，暂停管理 worker 的系统服务，再用旧代码/PHP 对实际连接和队列执行 `php artisan queue:work --force --stop-when-empty`。非默认队列需指定连接及 `--queue=队列名`。确认待处理和延迟任务均已处理完后再升级，避免旧序列化任务由新版本处理；`QUEUE_DRIVER=sync` 可跳过排空操作。
2. 备份最终数据库。PowerShell 使用 `--result-file`，避免重定向改变 SQL 文件编码：

   ```powershell
   mysqldump --host=127.0.0.1 --user=实际数据库用户 -p --single-transaction --routines --triggers --result-file=C:\backups\forum-before-laravel13.sql 实际数据库名
   ```

   同时保存 `.env`、Passport 密钥、上传目录和旧发布目录，备份放在 Web 根目录之外。

3. 原地发布时，先在旧代码下执行 `config:clear`、`route:clear`、`view:clear`。部署新代码和 lock、保留持久化文件；切换 CLI、IIS/FastCGI、worker 到 PHP 8.4/8.5。新发布目录不要复制旧 Laravel 缓存，维护文件 `storage/framework/down` 在切换期间保持有效。执行：

   ```shell
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

4. 原 `MAIL_DRIVER=directmail` 和 `ALIYUN_*` 配置可继续使用。如新增 `MAIL_MAILER`，确保与实际渠道一致，不要带入示例的 log 值。SMTP 的旧 `MAIL_ENCRYPTION=tls` / `ssl` 保持加密要求；新的 STARTTLS 配置使用 `MAIL_SCHEME=smtp`、`MAIL_REQUIRE_TLS=true`。上传保持本地 public 磁盘；旧七牛、社交登录变量可移除。当前 DirectMail 适配无附件通知。
5. 回收对应 IIS 应用池/重载 PHP，让 OPcache 生效。按原队列连接执行 `php artisan queue:restart` 并恢复管理 worker 的服务；`QUEUE_DRIVER=sync` 无需独立 worker。不要批量重试旧版本失败任务载荷。
6. 执行 `php artisan up`，立即抽查登录、正文、搜索、发帖/上传和邮件。确认后停止此项目的 Elasticsearch，保留旧数据/配置至回退窗口结束。若验收失败，重新进入维护并回退。

## 回退

1. 停止新版本写入及 worker，保持维护状态。
2. 恢复旧发布目录、PHP 7.4、数据库备份、原 `.env`、APP_KEY 和 Passport 密钥；窗口内如有新写入，先另行备份以供人工核对。
3. 按旧 lock 安装依赖，清除/重建旧版本配置和路由缓存，恢复 Elasticsearch 和旧队列服务。
4. 回收应用池，验证旧账号登录和正文读取后执行旧版本的 `php artisan up`。

## 本地验证

```powershell
$forumPhp = "$env:USERPROFILE\.config\herd\bin\php84\php.exe"
$forumComposer = "$env:USERPROFILE\.config\herd\bin\composer.phar"
& $forumPhp $forumComposer validate --strict
& $forumPhp $forumComposer check-platform-reqs
& $forumPhp vendor/bin/phpunit
& $forumPhp tests/runtime-smoke.php
```

PHPUnit 和缓存检查不读本地 `.env`，不使用本地 Passport 密钥，不连接生产数据库。替换 php84 为 php85 可再次验证。

复验 MySQL 时，启动 Docker 并创建独立容器（仅本机随机端口，只保存测试数据）：

```powershell
docker run --detach --rm --name codex-api-mud-upgrade-mysql57 --label codex.task=modernize-forum-backend --env MYSQL_ALLOW_EMPTY_PASSWORD=yes --env MYSQL_DATABASE=codex_forum_upgrade_test --publish 127.0.0.1::3306 mysql:5.7.40
docker exec codex-api-mud-upgrade-mysql57 mysqladmin ping --silent
docker port codex-api-mud-upgrade-mysql57 3306/tcp
# 等待 mysqld is alive，并填入上面的随机端口。
$env:FORUM_TEST_MYSQL_PORT='实际随机端口'
& $forumPhp tests/mysql-smoke.php
docker stop codex-api-mud-upgrade-mysql57
Remove-Item Env:FORUM_TEST_MYSQL_PORT
```

脚本仅接受本机非 3306 端口、MySQL 5.7.40、固定名称的空测试库。先安装历史表并写入历史样例，再执行增量迁移，核验原 secret 登录、个人令牌、RAG、搜索、编辑、可见性和 JSON 计数。重跑需重新创建空容器，不要修改脚本连接现网。

框架要求参考 [Laravel 13 升级文档](https://laravel.com/docs/13.x/upgrade)，数据库支持参考 [Laravel 数据库文档](https://laravel.com/docs/13.x/database)。精确版本和验证结果见 [OpenSpec 验证记录](../openspec/changes/modernize-forum-backend/verification.md)。
