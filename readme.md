# mud.ren 论坛 API

[mud.ren 社区论坛](https://bbs.mud.ren)的后端，使用 Laravel 13、Passport 13 和 MySQL。前端源码：[bbs.mud.ren](https://github.com/oiuv/bbs.mud.ren)。开发约定见 [AGENTS.md](AGENTS.md)，现有服务器升级见 [Laravel 13 升级指南](docs/upgrade-laravel13.md)。

## 运行环境

- PHP 8.3+、Composer 2，精确依赖以 `composer.lock` 为准。本地使用 Herd PHP 8.4 / 8.5。
- MySQL 5.7.40 或更新版本；本次升级不要求更换数据库。
- 扩展要求用 `composer check-platform-reqs` 核验；数据库需 PDO MySQL，头像需 GD，测试另需 PDO SQLite。
- 普通搜索直接查询 MySQL，支持标题/正文子串、分页和高亮。无需 Elasticsearch、IK、Scout 或索引同步。高级搜索继续使用前端已有的 Dify / mudrenRAG。

## 全新安装

以下初始化命令仅用于新数据库。已有论坛请按升级指南操作，保留原密钥和客户端。

Windows PowerShell / Herd 可明确指定 PHP，避免误用全局 PHP 7.4：

```powershell
$forumPhp = "$env:USERPROFILE\.config\herd\bin\php84\php.exe"
$forumComposer = "$env:USERPROFILE\.config\herd\bin\composer.phar"
& $forumPhp $forumComposer install
Copy-Item .env.example .env
& $forumPhp artisan key:generate
```

配置 `.env` 中的数据库、`APP_URL`（API 地址）和 `APP_SITE_URL`（前端地址），然后执行：

```powershell
& $forumPhp artisan migrate
& $forumPhp artisan passport:keys
& $forumPhp artisan passport:client --personal --provider=users --name="Forum registration" --no-interaction
& $forumPhp artisan passport:client --password --provider=users --name="Forum frontend" --no-interaction
& $forumPhp artisan storage:link
& $forumPhp artisan serve
```

将密码授权客户端输出的 ID 和明文 secret 配置到前端 `VUE_APP_AUTH_CLIENT_ID` / `VUE_APP_AUTH_CLIENT_SECRET`。secret 只显示一次，数据库保存哈希。现有前端使用带 secret 的密码授权，不添加 `--public`。

网站根目录指向 `public/`；IIS 和 CLI 都需使用目标 PHP。`storage/`、`bootstrap/cache/` 需要写权限，`public/storage` 链接到 `storage/app/public`。Windows 创建链接需满足本机符号链接权限。

`DatabaseSeeder` 不创建默认账号或板块。先注册并完成邮件激活，管理员由 `users.is_admin` 控制；创建板块后再发帖。

## 邮件、验证码与上传

开发示例使用 `MAIL_MAILER=log`，邮件写入本地日志。生产保留 SMTP 或阿里云 DirectMail：

```dotenv
MAIL_MAILER=directmail
MAIL_FROM_ADDRESS=已在阿里云配置的发信地址
MAIL_FROM_NAME=mudren
ALIYUN_ACCESS_KEY_ID=填写现有配置
ALIYUN_ACCESS_KEY_SECRET=填写现有配置
ALIYUN_REGION_ID=cn-hangzhou
ALIYUN_FROM_ADDRESS=已在阿里云配置的发信地址
ALIYUN_FROM_ALIAS=mudren
```

兼容旧 `MAIL_DRIVER`、`MAIL_FROM_USER`；新变量 `MAIL_MAILER`、`MAIL_FROM_NAME` 优先。SMTP 使用 `MAIL_HOST`、`MAIL_PORT`、`MAIL_USERNAME`、`MAIL_PASSWORD`；端口 465 配置 `MAIL_SCHEME=smtps`，STARTTLS 使用 `smtp` 并设置 `MAIL_REQUIRE_TLS=true`。旧 `MAIL_ENCRYPTION=tls` 会继续强制 TLS，`ssl` 继续使用隐式 TLS。DirectMail 适配器用于现有无附件的论坛通知。

发布和注册验证码分别配置 `CAPTCHA_ID_PUBLISH` / `CAPTCHA_SECRET_PUBLISH`、`CAPTCHA_ID_REGISTER` / `CAPTCHA_SECRET_REGISTER`。敏感词可放在 `storage/SensitiveWords.txt`，每行一个。

图片和头像沿用本地 `public` 磁盘，上传接口返回 `path/url/location/disk`。七牛与 GitHub/Google/Facebook 登录已移除；历史 `profiles` 数据保留。注册、账号密码登录及令牌认证继续使用 Passport。

异步队列沿用 `QUEUE_DRIVER`，默认 `sync`。现网如使用 Redis/数据库队列，继续配置对应连接并运行 `php artisan queue:work`。跨版本发布前排空旧队列，部署时重启工作进程。

## API 与 RAG

路由没有 `/api` 前缀。搜索为 `GET /threads/search?q=关键词&page=1`，保留 `data/links/meta` 和 `highlights.title/content`，每页 10 条、标题命中优先。空查询返回空分页，最长 100 字符，特殊字符按字面匹配。只查询公开主题，不检索评论。

RAG 的 `/threads/{id}` 和 `content.markdown`、原主题 ID、`App\Thread` 多态类型保持不变。没有共享的每分钟 60 次 API 配额；验证码、登录权限和发帖频率限制仍然生效。

CORS 默认由 IIS/反向代理提供，`config/cors.php` 中 `paths` 保持为空。若改用 Laravel 处理跨域，将其设为 `['*']` 并移除服务器重复的 CORS 响应头。

## 验证

安装开发依赖后执行：

```powershell
& $forumPhp $forumComposer validate --strict
& $forumPhp $forumComposer check-platform-reqs
& $forumPhp vendor/bin/phpunit
& $forumPhp tests/runtime-smoke.php
& $forumPhp vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --dry-run app/Thread.php
```

PHPUnit 使用内存 SQLite、临时 Passport 密钥和邮件/队列/CAPTCHA 替身，跳过本地 `.env` 与生产缓存。缓存启动检查同样隔离配置，并清理自己的临时文件。MySQL 独立容器验证见[升级指南](docs/upgrade-laravel13.md)和 [OpenSpec 验证记录](openspec/changes/modernize-forum-backend/verification.md)。

## 依赖兼容说明

邮件改用 Symfony Mailer 及项目内 DirectMail 适配器；表情转换使用 JoyPixels，上传使用兼容新框架的 uploader。保留 `laravel-follow` 1.x 的关系表语义；Parsedown 暂约束在 1.7.x，以兼容当前稳定版 ParsedownExtra 及既有 Markdown 输出。它们不是遗漏升级，后续更换需单独验证历史内容和关系数据。

## License

MIT
