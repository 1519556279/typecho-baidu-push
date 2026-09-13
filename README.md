# StellarPush · Typecho 多引擎搜索引擎推送插件

自动收集全站已发布内容 URL，主动推送给 **百度 / Bing(IndexNow)** 等多引擎加速收录。由原 BaiduPush 升级为多引擎版本，界面全新可视化。

## ✨ 功能

- **多引擎推送**：百度普通收录 API + IndexNow（Bing/Yandex/Naver/Seznam 等），360 / 头条预留接入
- **全站收集**：自动收集文章 / 独立页面 / 分类 / 标签 / 首页 URL
- **引擎卡片**：每引擎一张卡，显示「已就绪/未配置」+ 今日成功/失败/累计 + 一键单独推送
- **全部推送**：顶部大按钮同时推百度 + IndexNow
- **IndexNow 零配置**：自动生成密钥并托管 `key.txt`，发文 / 定时自动推送
- **定时自动推送**：每天最多一次（依赖站点访问触发，亦可计划任务 curl）
- **发布即推**：新文章发布、更新后实时推该条
- **失败重试**：失败 URL 自动记录，一键重试；配额用尽自动跳过次日重推
- **可视化统计**：KPI 概览 + 各引擎成功/失败柱状图 + 近 7 天推送趋势折线图（纯 CSS/SVG，无外部依赖）
- **作者信息**：面板顶部「👤 作者 咔咔」跳转 GitHub
- **使用说明**：面板内「📖 使用说明」下拉，分引擎讲解接入方式
- **自动 Sitemap**：推送成功后自动生成 / 更新 `sitemap.xml`
- **robots.txt 管理**：面板内直接编辑保存
- **安全**：Token 不落仓库；触发密钥鉴权；面板管理员鉴权；CSV 导出

## 📦 安装

1. 将本仓库文件夹（`StellarPush/`）放入 Typecho 的 `usr/plugins/` 目录，插件名为 `StellarPush`
2. 后台 **插件** 页启用「StellarPush」（原 BaiduPush 可停用删除）
3. 后台顶栏出现「🚀 推送」入口，点击进入面板

## ⚙️ 配置（插件 → 设置）

| 配置项 | 说明 |
| --- | --- |
| 百度推送 Token | 百度站长平台 → 资源提交 → 普通收录 → 推送接口 里的 token |
| 站点地址 | 含协议，末尾不带斜杠，如 `https://example.com` |
| 推送模式 | 增量（推荐）/ 全量 |
| 定时自动推送 | 开启后每天自动推送一次 |
| 触发密钥 | 用于计划任务 curl 触发（可留空，插件用自动生成的内部密钥自触发） |

> IndexNow 无需配置：插件自动生成密钥并托管 `{key}.txt`，必应站长平台「IndexNow」页可核对。

## 🔧 计划任务触发（可选）

```
curl "https://example.com/usr/plugins/StellarPush/push.php?key=你的密钥&mode=incremental"
```

`mode` 支持 `incremental` / `full` / `dry`（干跑）/ `single&cid=文章ID`。

## 🧩 兼容性

- Typecho 1.2+ / 1.3（建议 1.3），PHP 7.4+（需 curl 扩展）
- 数据库：SQLite / MySQL 自动适配

## 📄 License

MIT