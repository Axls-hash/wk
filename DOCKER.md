# wk_dl Docker 部署说明

## 本地 / 自建机（推荐：MySQL）

```bash
cd wk_dl
cp .env.docker.example .env
# 编辑 .env：填 ADMIN_*、SKYRIVER_* 等

docker compose up -d --build
# 打开 http://127.0.0.1:8000
# 健康检查 http://127.0.0.1:8000/api/health
```

## 仅演示（SQLite，无独立数据库）

```bash
docker compose -f docker-compose.sqlite.yml up -d --build
```

## 推到 orkestr.eu

orkestr **只能从 Git 仓库构建**，不能直接上传本地文件夹。

1. 本机安装 Git，把本目录推到 GitHub/GitLab（需含 `Dockerfile`）
2. 打开 https://console.orkestr.eu/settings → Connected Providers → 连接 GitHub
3. Projects → New Project → 选该仓库
4. Environment Variables 填入 `.env.docker.example` 里的关键项  
   - 若无 MySQL 插件：先设 `ALLOW_SQLITE=1` 演示  
   - 生产请挂外部 MySQL，设 `DATABASE_URL=mysql://...`
5. Deploy → 等待 Live → 访问 `xxx.orkestr.run`

端口：容器内监听 **8000**（`ADDR=:8000`）。
