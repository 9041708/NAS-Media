# NAS影库 - 自建媒体服务器

> **v3.1.0** | 类似 Emby/Plex 的自建媒体服务器，专为 NAS 设计。PHP + MySQL 架构，轻量易部署。

## 功能特性

### 媒体管理
- **海报墙展示** - 自动从 TMDB 获取电影海报、背景、简介、评分、导演、演员等元数据
- **媒体库分组** - 首页按媒体库分类展示（Emby 风格），每个媒体库独立横向滚动行
- **媒体库排序** - 管理后台可调整媒体库显示顺序，首页导航和布局即时生效
- **智能匹配** - 自动解析文件名匹配 TMDB 元数据，支持手动匹配
- **元数据管理** - 树形文件夹层级的元数据管理页，支持搜索、编辑、TMDB 重新匹配
- **详情页** - 影视独立详情页，大背景封面+横向滚动剧集卡片+演职人员+相似推荐
- **合集系统** - 创建合集、添加影片，首页独立合集入口
- **收藏功能** - 用户可收藏喜欢的影片
- **播放记录** - 记录播放历史和进度，支持继续观看

### 播放器
- **音频轨切换** - 多音轨自动生成 HLS 流（无损重封装），hls.js 实时切换
- **字幕切换** - 支持内嵌字幕（自动提取）和外挂字幕（srt/ass/ssa/vtt）
- **字幕搜索/下载** - 多源字幕搜索（OpenSubtitles / TheSubDB / 本地），一键下载添加
- **画质切换** - FFmpeg 转码 HLS，支持 360p/480p/720p/1080p 四档
- **倍速播放** - 0.5x ~ 3x 七档倍速
- **快进/快退** - 键盘方向键 ±10s/±30s，快捷键 J/L
- **下一集** - 剧集结束自动倒计时播放下一集，N/P 快捷键
- **跳过片头/片尾** - 进度条区间标记，自动弹出跳过按钮
- **画中画** - 浏览器原生 PiP 模式
- **全屏** - 双击或 F 键切换
- **播放位置记忆** - 自动记忆进度，下次续播
- **秒返回** - sendBeacon 非阻塞退出，极速返回首页

### 用户系统
- **多用户** - 管理员/普通用户角色，管理员可创建/编辑/删除用户
- **权限组** - 自定义 VIP/普通权限组，可设剧集上限和电影时长限制
- **用户注册** - 支持自助注册（可关闭）
- **用户偏好** - 每个用户独立的字幕/音轨/画质/倍速偏好
- **活跃会话** - 管理后台实时查看谁在看什么

### 管理后台
- **仪表盘** - 统计概览（影片数/用户数/播放次数）
- **媒体库管理** - 添加/删除/扫描/排序媒体库
- **元数据管理** - 树形目录浏览，展开折叠，内联编辑
- **用户管理** - 用户列表、创建/编辑、权限组配置
- **转码管理** - 搜索影片选择文件进行 HLS 转码
- **活跃会话** - 实时查看在线播放用户
- **消息推送** - 向在线用户发送弹窗消息
- **关于** - 系统信息概览（PHP版本/TMDB/FFmpeg/媒体库列表）
- **设置** - TMDB API Key、FFmpeg 路径、SMTP 邮件、转码开关等

## 系统要求

- PHP 7.4+ (推荐 8.1)
- MySQL 5.7+ / MariaDB 10.3+
- PHP 扩展: PDO, PDO_MySQL, cURL, JSON
- FFmpeg + FFprobe (画质转码/音视频探测，可选)
- Web 服务器: Nginx 或 Apache

## 快速部署

### 方式一: Docker 部署 (推荐)

```bash
# 修改 docker-compose.yml 中的视频路径
# 将 /volume1/video 替换为你的 NAS 视频目录

docker-compose up -d

# 访问 http://你的NAS-IP:8908/install.php 完成安装
```

### 方式二: 手动部署

1. 将项目文件复制到 Web 服务器根目录
2. 创建数据库: `mysql -u root -p < database.sql`
3. 复制配置文件: `cp config.sample.php config.php`
4. 编辑 `config.php` 填入数据库信息
5. 访问 `http://你的地址/install.php` 完成安装

### 方式三: NAS 套件安装

适用于群晖 DSM、威联通 QTS 等支持 PHP 的 NAS 系统:

1. 在 NAS 的 Web Station 中创建虚拟主机
2. 将项目文件放入对应目录
3. 确保 PHP 版本 >= 7.4 且已启用 PDO_MySQL
4. 访问安装页面完成配置

## 目录结构

```
├── api/                    # API 接口
│   ├── media.php           # 媒体数据接口 (含 media_tree 树形结构)
│   ├── scan.php            # 扫描管理接口 (含 reorder_libraries)
│   ├── stream.php          # 视频流接口 (Range分段)
│   ├── subtitle.php        # 字幕服务接口 (含 search/download)
│   ├── transcode.php       # 转码管理接口 (含 multi_hls 多音轨)
│   ├── activity.php        # 活跃会话接口
│   └── auth.php            # 认证/用户管理接口
├── includes/               # 核心类库
│   ├── Database.php        # PDO 数据库封装
│   ├── TmdbApi.php         # TMDB API 客户端 (带缓存)
│   ├── MediaScanner.php    # 媒体扫描器 (音频/字幕轨提取)
│   ├── FFmpeg.php          # FFmpeg 转码/探测核心
│   ├── Auth.php            # 认证/权限管理
│   ├── helpers.php         # 全局辅助函数
│   └── session.php         # Session 安全配置
├── assets/
│   ├── css/
│   │   ├── style.css       # 主样式 (分组/海报墙/导航/弹窗)
│   │   ├── poster.css      # 海报卡片样式
│   │   ├── player.css      # 播放器完整样式
│   │   └── admin.css       # 后台管理样式 (树形视图)
│   ├── js/
│   │   ├── app.js          # 前端主逻辑 (分组显示/详情页)
│   │   ├── player.js       # 全功能播放器 (多音轨/字幕搜索)
│   │   └── admin.js        # 后台管理 (元数据树/排序)
│   └── images/             # SVG 占位图
├── admin/index.php         # 管理后台 SPA
├── index.php               # 首页 (分组海报墙)
├── show.php                # 影视详情页
├── player.php              # 全功能播放器
├── history.php             # 播放历史页
├── about.php               # 公开关于页
├── actor.php               # 演员详情页
├── login.php               # 登录/注册页
├── install.php             # 安装向导
├── recovery.php            # 密码重置
├── database.sql            # 数据库结构 (完整版)
├── config.sample.php       # 配置模板
├── CHANGELOG.md            # 更新日志
├── docker-compose.yml      # Docker 编排
├── Dockerfile              # PHP+Apache 镜像
└── nginx.conf              # Nginx 配置示例
```

## 键盘快捷键

| 按键 | 功能 |
|---|---|
| `空格` / `K` | 播放 / 暂停 |
| `→` | 快进 10s |
| `←` | 快退 10s |
| `Shift+→` | 快进 30s |
| `Shift+←` | 快退 30s |
| `J` | 快退 10s |
| `L` | 快进 10s |
| `↑` / `↓` | 音量 +/- |
| `M` | 静音 |
| `F` | 全屏 |
| `N` | 下一集 |
| `P` | 上一集 |
| `[` / `]` | 倍速 -/+ 0.25x |

## TMDB API Key 申请

1. 注册 [The Movie Database](https://www.themoviedb.org/) 账号
2. 进入 [API 申请页面](https://www.themoviedb.org/settings/api)
3. 申请 API Key (选择 Developer 用途)
4. 在管理后台 设置 页面填入 API Key

## 使用说明

1. 访问 `/install.php` 完成初始化安装
2. 登录管理后台 `/admin/`
3. 添加媒体库 - 填入 NAS 上的视频文件夹路径
4. 点击扫描 - 系统会自动发现视频文件并匹配元数据
5. 返回首页即可看到海报墙

## 许可证

MIT License
