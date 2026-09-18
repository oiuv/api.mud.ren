# Spec Delta

## Purpose

为小规模论坛提供无需独立搜索服务的普通帖子查询，使用户能通过中文或其他文字找到公开主题，并继续使用现有前端分页与高亮显示；复杂语义检索由既有 AI 功能承担。

## ADDED Requirements

### Requirement: Literal public thread search
系统 SHALL 在 `/threads/search` 接受 `q`，兼容备用参数 `query`，对主题标题和正文进行字面子串检索；查询 SHALL 只返回已发布、未删除、未封禁且作者已激活且未封禁的主题，不检索评论。前后空白 SHALL 被忽略，空查询 SHALL 返回空分页，非字符串及超过 100 字符的查询 SHALL 返回 422。

#### Scenario: Chinese substring
- **WHEN** 用户查询单字或多字中文，公开主题标题或正文包含该子串
- **THEN** 结果包含该主题且无需分词服务

#### Scenario: Literal special characters
- **WHEN** 查询包含 `%`、`_`、`!`、反斜杠或 SQL 引号
- **THEN** 系统按字面内容匹配，不能扩大匹配范围或改变查询语义

#### Scenario: Private content isolation
- **WHEN** 相同关键词也存在于草稿、未来发布时间、封禁、软删除或无效作者主题中
- **THEN** 返回结果及总数均不包含这些主题

#### Scenario: Empty or invalid query
- **WHEN** 查询为空白、缺失、数组或超过长度限制
- **THEN** 空白或缺失查询返回空分页，非法类型或超长查询返回 422

### Requirement: Compatible results and safe highlights
响应 SHALL 保留 `data`、`links`、`meta`，每页 10 条，标题命中优先，相同优先级按发布时间和 ID 倒序稳定排序。每条结果 SHALL 提供前端可读取的 `highlights.title` 和 `highlights.content` 数组；高亮 HTML SHALL 仅包含转义后的文本及系统生成的强调标记。

#### Scenario: Stable pagination
- **WHEN** 查询匹配超过一页且含标题命中和仅正文命中
- **THEN** 标题命中先出现，翻页顺序稳定且分页总数准确

#### Scenario: HTML-safe highlights
- **WHEN** 标题或正文含脚本、HTML 或尖括号，或查询本身含这些字符
- **THEN** 高亮保持文本含义，前端通过 HTML 渲染时不会执行用户提供的标记

### Requirement: Search service independence
系统 SHALL 在没有 Elasticsearch 服务或索引的情况下完成主题搜索及主题创建、编辑、删除，结果 SHALL 直接反映已提交的数据库内容。

#### Scenario: Search after content edit
- **WHEN** 主题正文修改并成功提交
- **THEN** 新关键词立即可查询，旧关键词不再命中且无需同步索引
