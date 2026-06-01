# 群晖 NAS 安装指南

全程网页操作，无需 SSH。

---

## 第一步：安装套件

打开群晖 **套件中心**，搜索安装：

1. **Web Station**
2. **MariaDB 10**（或 MariaDB 5）
3. **PHP 8.0**（Web Station 自带的 PHP 运行环境）

> PHP 在 Web Station > 脚本语言设置 中安装，选择 8.0 或 8.1 版本。

---

## 第二步：MariaDB 设置

1. 打开 **MariaDB 10** 套件
2. 点击 **设置**
3. 勾选 **启用 TCP/IP 连接**（端口保持 3306）
4. 设置 root 密码（记住它，安装时要用）
5. 点击 **应用**

---

## 第三步：上传项目文件

1. 打开群晖 **File Station**
2. 进入 `web` 共享文件夹
3. 新建文件夹，命名为 `nas-media`
4. 将项目所有文件上传到这个文件夹里

上传后的结构应该是：

```
web/
  nas-media/
    index.php
    player.php
    login.php
    install.php
    ...
    api/
    includes/
    assets/
    admin/
```

---

## 第四步：Web Station 配置

### 4.1 创建虚拟主机

1. 打开 **Web Station**
2. 左侧点 **Web Service Portal**
3. 点 **新增** → **虚拟主机**
4. 填写：
   - **名称**：`nas-media`
   - **文档根目录**：选 `web` 下的 `nas-media` 文件夹
   - **HTTP 端口**：填 `8908`
   - **HTTPS 端口**：填 `8998`（想用 HTTPS 就填，不填就只有 HTTP）
   - **服务器**：选 Apache HTTP Server 2.4
   - **PHP**：选 PHP 8.0 或 8.1
5. 点 **下一步** → **应用**

> HTTP 和 HTTPS 端口都可以随便填，只要不跟群晖其他服务冲突就行。
> 填了 HTTPS 端口后用 `https://群晖IP:8998` 访问。
> 不填就用 `http://群晖IP:8908` 访问。
>
> 群晖自带自签证书，HTTPS 能直接用，浏览器会提示"不安全"但功能正常。
> 想去掉提示需要申请正式证书（在 控制面板 > 安全性 > 证书 中操作）。

### 4.2 检查 PHP 扩展

1. Web Station 左侧点 **脚本语言设置**
2. 点击 PHP 8.0 → **编辑** → **扩展** 选项卡
3. 确保勾选了 **pdo_mysql**
4. 保存

---

## 第五步：网页安装

浏览器访问（HTTP 或 HTTPS 都行）：

```
http://群晖IP:8908/install.php
或
https://群晖IP:8998/install.php
```

**步骤 1 - 数据库配置：**
- 数据库主机：`127.0.0.1`
- 端口：`3306`
- 用户名：`root`
- 密码：MariaDB 里设的 root 密码
- 数据库名：`nas_media`

点 **测试连接并初始化数据库** → 自动建库建表

**步骤 2 - 站点配置：**
- 填个站点名称
- TMDB API Key 可以先不填，以后在管理后台填
- 设个管理员密码

点 **完成安装**

---

## 第六步：使用

1. 访问 `http://群晖IP:8908/login.php` 登录
2. 进入管理后台 `http://群晖IP:8908/admin/`
3. **媒体库** → **添加媒体库**
4. 填入 NAS 上的视频文件夹路径：

| 共享文件夹名 | 填这个路径 |
|---|---|
| video | `/volume1/video` |
| movie | `/volume1/movie` |
| 外接 USB | `/volumeUSB1/usbshare1` |

5. 选类型（电影/剧集），点 **扫描**

等扫描完成，回到首页就能看到海报墙了。

---

## 填入 TMDB Key 获取海报

没有 TMDB Key 影片只有文件名，有 Key 才有海报/简介/评分。

1. 打开 https://www.themoviedb.org 注册账号
2. 进入 https://www.themoviedb.org/settings/api 申请 Key
3. 在管理后台 **设置** 中填入 Key
4. 对已有的影片点 **扫描** 或手动匹配即可补全

---

## 安装 FFmpeg（可选）

有 FFmpeg 才能：
- 获取视频分辨率/时长信息
- 转码为不同画质（360p/480p/720p/1080p）
- 提取内嵌字幕

**安装方法：**

1. 群晖 **套件中心** → **设置** → **套件来源** → 新增
2. 名称：`SynoCommunity`
3. 地址：`https://packages.synocommunity.com/`
4. 确定后搜索 **ffmpeg** 并安装
5. 在管理后台 **设置** 中填入路径：
   - FFmpeg 路径：`/usr/local/bin/ffmpeg`
   - FFprobe 路径：`/usr/local/bin/ffprobe`

---

## 常见问题

**Q: 扫描提示目录不存在？**
确认填的是物理路径（如 `/volume1/video`），不是共享文件夹名（如 `video`）。在 File Station 右键文件夹可以看到实际路径。

**Q: 播放视频没反应？**
检查 PHP 设置中 `open_basedir` 是否限制了视频目录，在 Web Station > PHP 设置中把 `open_basedor` 改成空白或 `none`。

**Q: 安装页面打不开？**
确认虚拟主机的端口和文档根目录配置正确，PHP 版本选的是 8.0/8.1。
