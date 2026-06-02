# 更新日志

## v3.2.0 (2026-06-02)

### 新增
- **网络设定** - 管理后台设置页新增外网访问开关，开启前弹出版权风险提示，需手动确认
- **VIP管理页面** - 管理后台独立VIP管理标签页，按媒体库批量设为VIP/取消VIP，支持全选/反选
- **图片代理缓存** - `/api/image.php` 代理TMDB图片并本地缓存7天，外网访问海报秒加载
- **刮削元数据** - 影视详情页"更多"菜单新增刮削元数据按钮，搜索TMDB重新匹配完整元数据
- **TMDB搜索链接** - 编辑元数据时TMDB ID旁增加搜索链接，点击跳转TMDB网站自行查找ID
- **安装锁定** - 安装完成后自动生成 `install.lock`，再次访问 `/install.php` 跳转首页，删除lock文件或用 `?force=1` 可重装
- **数据库迁移系统** - 新增 `update.php` 在线升级页和 `migrations/` 迁移脚本目录，覆盖文件后一键执行数据库变更
- **弹幕系统** - 播放器内置Canvas弹幕引擎，支持滚动/顶部/底部三种模式，彩色选择，发送后实时显示；支持B站弹幕导入（输入cid一键拉取B站XML弹幕）
- **自动播放** - 播放器页面加载自动静音播放，点击视频或提示按钮取消静音
- **播放器剧集信息** - 播放器左上角显示剧名+第X季第Y集+集名（从TMDB获取）
- **新标签页播放** - 所有播放入口改为新标签页打开，避免返回造成504/缓存问题

### 改进
- **登录鉴权** - 首页/详情页/播放器/历史/演员页全面要求登录，未登录自动跳转登录页并支持redirect回跳
- **剧集标题优化** - 详情页每集卡片从TMDB拉取真实标题（如"第1集 · 试播集"），文件名改为灰色副标题
- **特别篇/番外识别** - 扫描器识别"番外/SP/Specials/OVA/花絮"等文件夹映射为第0季；元数据树显示为"特别篇/番外"
- **刷新元数据报错** - 失败时显示具体错误原因（如TMDB搜索无结果、API超时等），替代简单的"失败"提示
- **移动端适配** - 768px以下自动切换汉堡菜单导航，海报网格缩小，筛选栏竖向排列
- **登录页回跳** - 登录成功后自动跳回原始页面（支持 `?redirect=` 参数）
- **首页加载优化** - 合并多库查询为单次 `homepage` API请求，精简返回字段，加载速度大幅提升；15秒超时fallback
- **元数据管理默认收起** - 树形目录默认全部折叠，点击展开后才加载子节点，避免大面积DOM渲染卡顿
- **未匹配视图增强** - 未匹配页面新增"无海报/元数据影视"分组，可一键刷新
- **用户快速调权限** - 用户列表新增"权限"按钮，弹窗直选权限组保存即生效

### 修复
- **播放器JS解析错误** - 字幕下载代码缺闭合括号导致player.js整体无法执行，修复后所有按钮恢复正常
- **老用户更新兼容** - `update.php` 和 `install.php` 兼容无 `install.lock` 的老安装（检测config.php自动补建lock文件）
- **播放器HTML结构** - 修复player-topbar未闭合导致视频区嵌套布局崩塌

### 文件变更
- 新增 `api/image.php` - TMDB海报代理/缓存服务
- 新增 `api/danmaku.php` - 弹幕CRUD + B站XML导入
- 新增 `update.php` - 数据库在线迁移页面
- 新增 `migrations/1_initial_settings.sql` - 首个迁移脚本
- 新增 `migrations/2_danmaku.sql` - 弹幕表迁移
- 更新 `admin/index.php` - 网络设定/VIP管理/系统更新入口/TMDB搜索链接
- 更新 `assets/js/admin.js` - VIP批量进度条、远程访问风险弹窗、图片代理URL、用户快速调权限、元数据树默认收起
- 更新 `assets/js/app.js` - 单独的homepage API、图片代理URL、移动端汉堡菜单、15秒超时、非JSON容错、异步渲染修复
- 更新 `assets/js/player.js` - 弹幕引擎+轮询+B站导入、自动播放+静音提示、try-catch包裹
- 更新 `assets/css/style.css` - 移动端汉堡菜单、遮罩层、响应式优化
- 更新 `assets/css/player.css` - 播放器标题区、弹幕层/输入栏/导入栏样式
- 更新 `includes/MediaScanner.php` - 番外/特别篇文件夹识别（返回season 0）、刷新元数据异常抛出
- 更新 `includes/helpers.php` - `getPosterUrl()`/`getBackdropUrl()` 改为本地代理URL
- 更新 `api/media.php` - 新增 `scrape_meta`/`media_tree_vip`/`batch_vip`/`homepage` API，优化查询性能
- 更新 `api/scan.php` - `refresh_meta` 增加异常捕获返回错误详情
- 更新 `show.php` - 剧集标题展示、刮削元数据、TMDB搜索链接、登录保护、播放新标签页
- 更新 `index.php` - 登录保护、移动端汉堡菜单、图片代理URL
- 更新 `player.php` - 登录保护、剧集信息显示、自动播放、弹幕UI、B站导入UI、播放新标签页
- 更新 `login.php` - 登录后redirect回跳支持
- 更新 `history.php`/`actor.php` - 登录保护、图片代理URL、播放新标签页
- 更新 `user/index.php` - 图片代理URL、播放新标签页
- 更新 `install.php` - 安装锁定机制、`install.lock` 自动生成、老用户兼容
- 更新 `database.sql` - 新增 `remote_access_enabled`/`db_version` 配置项、弹幕表
- 更新 `config.sample.php` - 版本号更新
- 更新 `.gitignore` - 增加 `/install.lock`
- 更新 `README.md` / `CHANGELOG.md` - 文档维护

---

## v3.1.0 (2026-06-02)

### 新增
- **首页按媒体库分组** - Emby 风格分组展示，每个媒体库独立横向滚动海报行，导航栏动态显示媒体库名称
- **媒体库排序** - 管理后台 ▲▼ 按钮调整媒体库显示顺序，首页导航和分组即时生效
- **多音轨 HLS 流** - 播放器检测到多音轨时自动生成无损 HLS 流（`-c copy`），hls.js 实时切换音轨
- **字幕搜索与下载** - OpenSubtitles / TheSubDB / 本地文件多源搜索，一键下载或手动粘贴字幕直链
- **管理员关于页面** - 系统概览统计卡片、系统信息表（PHP版本/TMDB/FFmpeg状态）、媒体库列表
- **详情页内联编辑** - 管理员在 show.php 详情页可直接编辑标题/年份/简介/类型等元数据

### 改进
- **元数据管理重构** - 树形文件夹层级展示（媒体库→剧集→季→集），支持展开/折叠、内联编辑、TMDB 匹配
- **电影详情页** - 电影改为独立详情页 `/show.php?id=X`，替代弹窗模式，与剧集统一体验
- **播放器进度条修复** - 移除 `.video-area` 多余的 flex 布局，修复进度条在部分情况下不显示的问题
- **播放按钮文案** - 电影继续观看显示"继续观看"，剧集显示"继续 第X集"
- **TMDB 匹配** - 元数据管理未匹配文件列表支持一键匹配，匹配后自动刷新

### 修复
- **返回按钮提速** - 移除阻塞式 `pause()`，改用 `sendBeacon` + `location.replace()` 极速返回
- **活跃会话修复** - `last_heartbeat` 交由 MySQL `ON UPDATE CURRENT_TIMESTAMP` 自动维护，修复时区不一致导致会话不显示的问题
- **媒体库导航修复** - 点击媒体库名称时 `type` 参数误传为 `'library'` 导致查不到影片，改为 `'all'`

### 文件变更
- 重写 `admin/index.php` 元数据面板 → 树形目录视图
- 重写 `assets/js/admin.js` 元数据管理、媒体库排序逻辑
- 更新 `api/media.php` - 新增 `media_tree`/`media_tree_unmatched`/`library_id` 过滤
- 更新 `api/transcode.php` - 新增 `multi_hls`/`multi_playlist`/`multi_segment`
- 更新 `api/subtitle.php` - 新增 `search`/`download`，修复 `$this->` 调用错误
- 更新 `api/scan.php` - 新增 `reorder_libraries`、`add_library` 自动排号
- 更新 `index.php` - 导航栏动态媒体库链接、`sort_order` 排序
- 更新 `assets/js/player.js` - 多音轨 HLS 初始化、字幕搜索弹窗
- 更新 `show.php` - 内联编辑弹窗、播放按钮文案优化
- 更新 `player.php` - 字幕搜索按钮、播放数据传递
- 新增 `assets/css/admin.css` - 树形视图、排序徽标样式
- 新增 `assets/css/player.css` - 字幕搜索弹窗、修复视频区布局
- 新增 `assets/css/style.css` - 媒体库分组样式
- 更新 `database.sql` - `libraries` 表新增 `sort_order` 字段
- 更新 `includes/FFmpeg.php` - 新增 `getFfmpegPath()`/`getFfprobePath()` 公共方法
- 更新 `api/activity.php` - 修复 `last_heartbeat` 时区问题、`stop` 支持 POST body 降级
- 更新 `assets/js/app.js` - 媒体库导航 type 参数修复、电影跳转详情页、分组渲染
- 更新 `README.md` - v3.1.0 功能特性更新、目录结构完善

---

## v3.0.0 (2026-06-01)

### 新增
- **Emby 风格详情页** - 大背景封面 + 横向滚动剧集卡片 + 单集简介（TMDB 自动获取）
- **演职人员展示** - 横向滚动演员卡片带 TMDB 照片，点击演员名筛选其所有影视
- **"更多类似"推荐** - 基于 TMDB 相似/推荐算法显示关联影视
- **预告片内嵌播放** - YouTube 视频嵌入，无需跳转外部页面
- **合集系统** - 创建合集、添加/移除影片，首页导航栏新增"合集"入口
- **播放状态** - 标记已看/未看，已看影片显示绿色对勾
- **更多操作菜单** - 从头播放、随机播放、添加到合集、标记已看、编辑元数据、刷新元数据、扫描媒体文件
- **用户权限组** - 自定义权限组（VIP/普通），可分别设置剧集集数限制和电影分钟限制
- **VIP 影片** - 元数据标记 VIP 专属内容，非 VIP 组播放时拦截提示
- **播放权限校验** - player.php 播放前校验 VIP 限制、剧集上限、电影时长上限
- **继续观看** - 首页 Hero 下方横向滚动栏，进度条显示已看百分比
- **播放记录悬浮下拉** - 右上角时钟图标 hover 显示最近 6 条播放记录
- **独立播放记录页** - `/history.php` 按今天/昨天/日期分组展示
- **管理员右键菜单** - 首页海报右键弹出"刷新元数据"/"重新匹配 TMDB"
- **转码媒体选择** - 搜索影片名选择文件转码，替代手动输入文件 ID
- **元数据管理** - 管理后台元数据管理页，表格展示/搜索/批量编辑/刷新
- **Session 隔离** - 自定义 session 名 + SameSite Strict + UA/IP 指纹绑定，防止跨站点 session 混用
- **TV 剧集文件夹识别** - 按 剧名文件夹→季文件夹→集文件 结构扫描，自动提取集号（E01/EP01/第01集等）
- **季/集号持久化** - media_files 表新增 season_number/episode_number 字段

### 改进
- 播放入口优化：首页电视剧海报点击直接进入详情页，不再弹窗
- 播放器选集面板：底部滑入式面板替代顶部下拉框，列表图标按钮触发
- 返回按钮提速：点击后先暂停视频再跳转，秒返回不卡顿
- 进度条拖拽精度修复：消除 CSS `right:-6px` 与 JS `left` 冲突导致的偏移
- 音量滑块始终可见：默认 60px 宽，替代 hover 才显示的隐藏设计
- 删除媒体库时自动清理孤立元数据
- 数据库 schema 合并为单一 `database.sql`（15 张表 + 默认数据）
- 合集数据库：新增 `collections` / `collection_items` 表
- 权限组数据库：新增 `user_groups` 表，users 表增加 `group_id` 字段

### 文件变更
- 新增 `show.php` - Emby 风格影视详情页
- 新增 `history.php` - 独立播放记录页
- 新增 `includes/session.php` - Session 隔离配置
- 重写 `includes/MediaScanner.php` - TV 文件夹结构扫描、季/集号提取
- 更新 `api/media.php` - 新增 10+ API 端点（credits/similar/collection/season/trailer 等）
- 更新 `api/auth.php` - 权限组 CRUD、用户分组
- 更新 `admin/index.php` - 元数据管理、转码选择、用户选项卡、权限组管理
- 更新 `assets/js/admin.js` - 元数据管理、转码搜索、权限组、用户编辑
- 更新 `assets/js/app.js` - 合集浏览、演员筛选、继续观看、右键菜单
- 更新 `assets/js/player.js` - 选集面板切换、面板按钮
- 更新 `player.php` - 返回提速、选集面板、播放权限校验
- 更新 `index.php` - 继续观看栏、播放记录下拉、演员筛选
- 更新 `includes/TmdbApi.php` - 相似/推荐/演员照片/季详情/预告片 API
- 更新 `assets/css/player.css` - 进度条修复、音量常显、面板样式
- 更新 `assets/css/admin.css` - 元数据表格、转码选择器
- 更新 `assets/css/style.css` - 继续观看、播放记录下拉、右键菜单
- 合并 `database_v2~v5.sql` → `database.sql`（单文件完整结构，含 collections/user_groups）

---

## v2.0.0 (2026-05-31)

### 新增
- **多用户系统** - 注册/登录/角色权限（管理员/普通用户）
- **音频轨切换** - 播放器内置音轨选择器，支持多音轨视频切换
- **字幕切换** - 内嵌字幕 VTT 提取 + 外挂 srt/ass/ssa/vtt
- **画质切换** - FFmpeg HLS 转码，360p/480p/720p/1080p，HLS.js 自适应
- **倍速播放** - 0.5x ~ 3x 七档，用户偏好持久化
- **快进/快退** - 键盘方向键 ±10s/±30s，J/L 快捷键
- **下一集/上一集** - N/P 快捷键，剧集结束自动倒计时播放下一集
- **跳过片头/片尾** - 进度条区间标记，自动弹出跳过按钮
- **画中画/全屏** - 浏览器原生 PiP，双击/F 键全屏
- **播放位置记忆** - localStorage 续播

### 文件变更
- 新增 `includes/FFmpeg.php`、`api/transcode.php`、`api/subtitle.php`
- 新增 `assets/js/player.js`
- 重写 `player.php`、`assets/css/player.css`

---

## v1.0.0 (2026-05-31)

### 初始版本
- 海报墙展示，TMDB 元数据自动获取
- 视频文件夹扫描，智能文件名解析
- HTML5 视频播放，Range 分段传输
- 多媒体库管理（电影/剧集/其他）
- 收藏、播放历史、管理后台
- Docker 一键部署
