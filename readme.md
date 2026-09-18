
<img align="right" width="80" src="https://www.mud.ren/logo.png"/>

<h1 align="left"><a href="https://bbs.mud.ren">bbs.mud.ren</a></h1>

本项目是 [mud.ren 社区论坛](https://bbs.mud.ren) 的后端 API，基于 Laravel 6.x 开发，由本项目独立维护。

开发与 AI 协作约定见 [AGENTS.md](AGENTS.md)。

前端源码：[mudbbs](https://github.com/oiuv/bbs.mud.ren)。

## 运行环境要求

- PHP 7.4
- ElasticSearch 6.*
- [ElasticSearch ik 插件](https://github.com/medcl/elasticsearch-analysis-ik)

## 开发环境部署/安装

当前项目使用 PHP 7.4 和 Laravel 6.x，依赖版本以 `composer.lock` 为准。

下文将在假定读者已经安装好了 Homestead 的情况下进行说明。

### 基础安装

#### 克隆源代码

克隆源代码到本地：

    > git clone https://github.com/oiuv/api.mud.ren.git

#### 配置本地的 Homestead 环境

1). 运行以下命令编辑 Homestead.yaml 文件：

```shell
homestead edit
```

2). 加入对应修改，如下所示：

```
folders:
    - map: ~/WWWROOT/api.mud.ren/ # 你本地的项目目录地址
      to: /home/vagrant/api.mud.ren

sites:
    - map: api.mud.ren.test
      to: /home/vagrant/api.mud.ren/public

databases:
    - mud_ren
```

3). 应用修改

修改完成后保存，然后执行以下命令应用配置信息修改：

```shell
homestead provision
```

随后请运行 `homestead reload` 进行重启。

#### 安装扩展包依赖

	composer install

#### 生成配置文件

```
cp .env.example .env
```

你可以根据情况修改 `.env` 文件里的内容，如数据库连接、缓存、邮件设置等：

```
APP_URL=http://api.mud.ren.test
...
DB_HOST=localhost
DB_DATABASE=mud_ren
DB_USERNAME=homestead
DB_PASSWORD=secret
```

#### 生成数据表

在 Homestead 的网站根目录下运行以下命令

```shell
php artisan migrate
```

当前 `DatabaseSeeder` 没有预设数据，也不会创建默认管理员账号。

#### 生成秘钥

```shell
$ php artisan key:generate
```

#### Passport 初始化

```shell
$ php artisan passport:install
```

将生成的 password grant 对应的 id 与 secret 记录下来，用于配置前端应用的 env 变量。

#### 配置 hosts 文件

    echo "192.168.10.10   api.mud.ren.test" | sudo tee -a /etc/hosts

#### 其它服务配置
##### 腾讯 007 防水墙

去 [腾讯防水墙](https://007.qq.com/) 注册账号，创建验证码服务（你可能需要创建两个验证，一个用于发布文章，一个用于注册账号），获取对应的配置填写到 `.env` 中：

```env
# 用于发布文章的验证码
CAPTCHA_ID_PUBLISH=
CAPTCHA_SECRET_PUBLISH=

# 用于用户注册用的验证码
CAPTCHA_ID_REGISTER=
CAPTCHA_SECRET_REGISTER=
```

##### 帖子搜索服务

帖子搜索基于 [ElasticSearch](https://www.elastic.co/) 实现，所以你需要在任何机器上部署一个 ES 服务，然后将地址与索引名称配置到：

```env
ELASTICSEARCH_INDEX=mudren
ELASTICSEARCH_HOST=http://127.0.0.1:9200
```

##### 敏感词配置

请自行寻找敏感词库，将敏感词放置于 `storage/SensitiveWords.txt` 中，每行一个：

```bash
敏感词1
敏感词2
...
```

### 服务入口与管理员

- 论坛入口：https://bbs.mud.ren
- 本地 API：http://api.mud.ren.test（使用上述 Homestead 配置时）。

管理员身份由 `users.is_admin` 字段控制，管理操作通过论坛前端调用本项目 API。

## 扩展包使用情况

| **扩展包** | **一句话描述** | **本项目应用场景** |
| ---- | ---- | ---- |
| [overtrue/easy-sms](https://github.com/overtrue/easy-sms) | 多网关短信发送组件 | 发送验证码 |
| [overtrue/laravel-emoji](https://github.com/overtrue/laravel-emoji) | emoji 转换组件 | 帖子与评论 emoji 解析 |
| [overtrue/laravel-filesystem-qiniu](https://github.com/overtrue/laravel-filesystem-qiniu) | 七牛 CDN SDK | 帖子内容图片存储 |
| [overtrue/laravel-follow](https://github.com/overtrue/laravel-follow) | Laravel 用户关系组件 | 用户关注与帖子订阅 |
| [overtrue/laravel-mail-aliyun](https://github.com/overtrue/laravel-mail-aliyun) | 阿里云邮件 SDK | 发送通知邮件 |
| [overtrue/laravel-socialite](https://github.com/overtrue/laravel-socialite) | 社交登录组件 | 用户使用第三方登录 |
| [overtrue/laravel-uploader](https://github.com/overtrue/laravel-uploader) | Laravel 上传功能封装 | 帖子内容图片上传 |
| [overtrue/laravel-query-logger](https://github.com/overtrue/laravel-query-logger) | Laravel SQL 监听工具 | 开发环境查看 SQL 记录 |
| [Intervention/image](https://github.com/Intervention/image) | 图片处理功能库 | 用于图片裁切 |
| [guzzlehttp/guzzle](https://github.com/guzzle/guzzle) | HTTP 请求客户端 | 调用外部服务及初始化搜索索引 |
| [predis/predis](https://github.com/nrk/predis.git) | Redis PHP 客户端 | 缓存驱动 Redis 基础扩展包 |
| [mewebstudio/Purifier](https://github.com/mewebstudio/Purifier) | 用户提交的 Html 白名单过滤 | 帖子内容的 Html 安全过滤，防止 XSS 攻击 |
| [laravel/passport](https://github.com/laravel/passport) | 用户授权 | OAuth 2.0 令牌认证 |
| [laravolt/avatar](https://github.com/laravolt/avatar) | 生成用户头像 | 用户头像 |
| [sentry/sentry-laravel](https://github.com/getsentry/sentry-laravel) | Sentry 报错监控 | 监控系统错误 |
| [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog) | 用户行为记录 | 个人中心的用户动态 |
| [tamayo/laravel-scout-elastic](https://github.com/ErickTamayo/laravel-scout-elastic) | Laravel Scout ES 驱动 | 帖子搜索 |
| [tucker-eric/eloquentfilter](https://github.com/tucker-eric/eloquentfilter) | 模型字段过滤 | 接口字段过滤 |
| [vinkla/hashids](https://github.com/vinkla/hashids) | HashID 实现 | 保留的标识符编码依赖 |
| [beyondcode/laravel-self-diagnosis](https://github.com/beyondcode/laravel-self-diagnosis) | Laravel 基础环境检查工具 | 检查配置是否正确 |

## 自定义 Artisan 命令

| 命令行名字 | 说明 | Cron | 代码调用 |
| --- | --- | --- | --- |
| `es:init` | 初始化 ES 模板并重建索引，会删除原索引 | 无 | 无 |

## License

MIT

## 回归测试

安装开发依赖后使用项目现有的 PHP 7.4 运行（需要 PDO SQLite 扩展）：

```shell
php vendor/bin/phpunit
```

测试强制使用 SQLite 内存数据库，跳过本地 .env 和生产配置缓存，隔离队列、邮件、验证码与 Elasticsearch；不需要连接实际论坛数据库或外部 AI 服务。
覆盖用户资料权限、管理字段、用户名查询、密码重置有效期、帖子读写、搜索可见性及连续超过 60 次的正文读取。

## RAG 接入与修复部署

论坛 API 已取消统一的每分钟 60 次限流，`GET /threads/{id}` 可供 mudrenRAG 连续获取正文。JSON 格式与地址保持不变，RAG 无需改配置。发帖频率、验证码和登录权限检查仍然生效。

本次修复沿用当前 PHP / Laravel / Composer 依赖，不涉及数据库结构变更。部署代码后，按现有部署流程刷新路由缓存并重启队列工作进程；使用 IIS / PHP OPcache 时需回收对应应用池或重载 PHP 进程，使新代码生效：

```shell
php artisan route:clear
php artisan queue:restart
```

搜索结果会按数据库中的公开状态过滤旧索引命中。若上线后仍有 429，检查反向代理、IIS 或 CDN 上独立设置的限流；这些配置不由此仓库控制。
