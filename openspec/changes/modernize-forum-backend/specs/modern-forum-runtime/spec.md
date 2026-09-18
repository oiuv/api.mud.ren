# Spec Delta

## Purpose

使持续运营的论坛能够统一使用受维护的 PHP 运行环境，消除单独维持 PHP 7.4 的需要，同时保持现有数据库、前端交互和知识库读取契约，并提供可验证、可回退的升级流程。

## ADDED Requirements

### Requirement: Supported runtime and database compatibility
项目 SHALL 可在 PHP 8.3 及以上受目标框架支持的版本安装锁定依赖、启动并运行回归测试；部署目标 SHALL 为 Laravel 13，数据库 SHALL 保持 MySQL 5.7.40 兼容，部署 SHALL 不要求 Elasticsearch。

#### Scenario: Reproducible installation
- **WHEN** 在满足扩展要求的 PHP 8.x 环境安装提交的 lock
- **THEN** 平台要求检查、应用启动和自动化回归通过

### Requirement: Existing consumer contracts
后端 SHALL 保留现有无 `/api` 前缀的业务接口、密码授权登录、注册令牌、认证访问及令牌撤销；SHALL 保留用户、主题与内容标识及既有多态类型。公开主题响应 SHALL 保留 `content.markdown` 等 RAG 字段，连续公开读取 SHALL 不受共享 API 配额限制。

#### Scenario: Existing frontend authentication
- **WHEN** 前端使用已有客户端配置和正确密码调用 `/oauth/token`
- **THEN** 返回可用于访问 `/me` 的令牌，撤销后令牌不能继续访问受保护接口

#### Scenario: Knowledge retrieval
- **WHEN** RAG 通过历史主题 ID 连续读取公开主题
- **THEN** 返回兼容的主题与正文结构，私有主题仍不能被匿名读取

### Requirement: Business and security preservation
升级 SHALL 保持发帖、评论、关系、通知、上传、邮件及正文转换能力；SHALL 保持目标模型授权、字段白名单、CAPTCHA、发帖频率、内容净化及帖子内容事务完整性。

#### Scenario: Existing regression behavior
- **WHEN** 执行原有权限、内容、关系与通知回归及升级补充测试
- **THEN** 既有业务与安全断言通过，不能通过删除断言规避不兼容

#### Scenario: External integration isolation
- **WHEN** 自动化测试运行认证以外的外部服务适配
- **THEN** 测试使用受控替身，且部署说明清楚列出真实服务验收步骤

### Requirement: Reviewable maintenance upgrade
项目 SHALL 提供按短时维护窗口执行的备份、安装、增量迁移、验收和回退步骤，明确真实数据库与外部服务验证状态；升级 SHALL 不要求重建业务表或重新生成现网密钥。

#### Scenario: Deployment preparation
- **WHEN** 维护者按升级说明准备发布
- **THEN** 能区分已通过的本地检查和待执行的环境验收，并能用备份恢复旧版本
