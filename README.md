# Skylark Docker

使用 Docker Compose 部署 [Skylark v2.36.1](https://github.com/GreenNerd/skylark/releases/tag/v2.36.1)。生产镜像为 `registry.cn-hangzhou.aliyuncs.com/skylark/production:2.36.1-3d98cb1948`（`linux/amd64`）。基础服务包括 PostgreSQL 16、Redis 7.4、带 IK 分词的 Elasticsearch 8.19.20 和 [RustFS 1.0.6](https://github.com/rustfs/rustfs/releases/tag/1.0.6)。

## 首次部署

需要 Git、Docker Engine 和支持 `docker compose up --wait` 的 [Docker Compose 插件](https://docs.docker.com/compose/install/)。部署使用 Skylark 上游构建的阿里云生产镜像。

```bash
git clone --branch main https://github.com/GreenNerd/skylark-docker.git /var/skylark
cd /var/skylark
cp .env.example .env
cp rustfs.local.env.example rustfs.local.env
touch app.local.env
```

| 文件 | 用途 |
| --- | --- |
| `.env` | 选择 Skylark 镜像版本与平台，设置 RustFS 的宿主机监听地址和端口。 |
| `app.default.env` | 应用和容器默认值。 |
| `app.local.env` | 站点配置和应用密钥。 |
| `rustfs.local.env` | RustFS 的管理凭据 `RUSTFS_ACCESS_KEY` 和 `RUSTFS_SECRET_KEY`。 |

在 `rustfs.local.env` 设置一对专用的管理凭据。在 `app.local.env` 填入站点配置、数据库密码和 Rails 密钥。用 `openssl rand -hex 64` 生成 `SECRET_KEY_BASE`。管理员 ID 可通过 `SETTING_ROOT_IDS` 配置，格式为 `[1, 2]`。

```dotenv
NGINX_SERVER_NAME=skylark.example.com
NGINX_SERVER_PROTO=http
SETTING_APP_DOMAIN=http://skylark.example.com
POSTGRES_PASSWORD=
SECRET_KEY_BASE=
SETTING_MINIO_PUBLIC_ENDPOINT=http://skylark.example.com/s3
SETTING_MINIO_ACCESS_KEY=
SETTING_MINIO_SECRET_KEY=
```

- 使用 **HTTPS** 时，将 `NGINX_SERVER_PROTO` 设为 `https`，并将 `SETTING_APP_DOMAIN` 和 `SETTING_MINIO_PUBLIC_ENDPOINT` 改为对应的 HTTPS 地址。把证书和私钥放到 `etc/ssl/skylark.crt` 和 `etc/ssl/skylark.key`，在 `app.local.env` 设置部署所需的 `NGINX_SSL_CIPHERS`。多域名部署可用逗号分隔的 `CABLE_ALLOWED_ORIGINS` 指定 WebSocket 来源。
- Nginx 沿用仅允许**中国大陆地址**的访问规则。将经确认的 IPv4、IPv6 网段写入 `etc/nginx/skylark/geoip/country.conf`，每行格式为 `<CIDR> CN;`。维护地址表并核对允许访问的地址。没有匹配网段的请求会被拒绝。 [Nginx geo 模块](https://nginx.org/en/docs/http/ngx_http_geo_module.html)
- Linux 上先创建可写的 Elasticsearch 和 RustFS 数据目录。服务的数据和文件存放在 `data/`、`shared/` 和 `log/`。Elasticsearch 默认使用 2 GB JVM 堆。

```bash
sudo install -d -o 1000 -g 0 -m 0750 data/elasticsearch
sudo install -d -o 10001 -g 10001 -m 0750 data/rustfs
docker compose config --quiet
./scripts/install
```

阿里云镜像仓库要求认证时，先执行 `./scripts/login`。安装脚本拉取镜像，等待基础服务就绪，用 `db:prepare` 准备数据库并编译静态资源。

## 配置 RustFS 和启动站点

默认把新文件写到 RustFS。Skylark 镜像仍用 `SETTING_STORAGE_WRITE=minio`、`SETTING_MINIO_*` 和已有记录的 `storage_backend=minio` 标识 S3 后端。这些值对应 RustFS 的 S3 API，容器内地址为 `http://rustfs:9000`。

1. 用 RustFS 控制台创建 `SETTING_MINIO_BUCKET_*` 对应的桶。默认桶名为 `slp-main`、`slp-attachment`、`slp-exportable` 和 `slp-material`。为 Skylark 配置有这些桶权限的 `SETTING_MINIO_ACCESS_KEY` 和 `SETTING_MINIO_SECRET_KEY`。若开始时使用管理凭据，创建应用专用凭据后可在 `app.local.env` 切换。
2. 将 `SETTING_MINIO_PUBLIC_ENDPOINT` 设为浏览器可达的站点地址加 `/s3`，例如 `https://skylark.example.com/s3`。客户端访问的域名必须和签名使用的域名一致。
3. 启动站点并检查应用、后台任务和 Nginx 日志，再验证上传与下载。

```bash
./scripts/start
docker compose ps
docker compose logs --tail=100 app sidekiq web
```

RustFS 的 API 和控制台默认仅在宿主机 `127.0.0.1` 上发布，分别使用 9000 和 9001。远程管理时可用 `ssh -L 9001:127.0.0.1:9001 <部署主机>`，然后访问 `http://127.0.0.1:9001/rustfs/console/`。Compose 中的 RustFS 使用单节点、单磁盘存储。 [RustFS 容器安装](https://docs.rustfs.com/en/installation/container/docker)

需要初始命名空间时，运行 `docker compose exec app bundle exec rails console`，然后执行 `Namespace.create!(name: '空间名称')`。可用 `./scripts/logrotate` 将轮转日志保存到 `old_log/`。

## 升级现有部署

1. **备份数据。** 用 `./scripts/backup` 在 PostgreSQL 数据目录生成 dump，将其与 `shared/`、对象存储及本地配置备份到独立位置。保留实际使用的 `SECRET_KEY_BASE`。若旧部署只留了 `SECRETS_KEY_BASE`，请在核对实际值后将其填入 `SECRET_KEY_BASE`。
2. **切换部署分支。** 用 `git fetch origin` 和 `git switch main` 切换到 `main`，再用 `git pull --ff-only origin main` 更新。在现有 `.env` 更新 `SLP_VERSION`，补齐 RustFS 凭据和 Nginx 地址表。旧部署有 S3 对象存储时，先完成下文的对象迁移。
3. **准备基础服务。** PostgreSQL、Redis、Elasticsearch 跨大版本时，先按各自的流程迁移数据。Elasticsearch 8.19.20 用新的数据目录或集群替换旧的 5.x、7.x 实例。准备完成后用 `docker compose up -d --wait db redis elasticsearch rustfs` 启动基础服务。
4. **检查旧表映射。** 从 2.36.0 之前升级时，`RemoveLegacyTableMapper` 会删除旧映射 Webhook 和命名空间的 `table_mapper` 设置。仍在使用这些映射的站点应先记录旧配置，再在新版本中按命名空间配置映射目标（sink）。
5. **更新 Skylark。** 执行 `docker compose config --quiet` 与 `./scripts/update`。升级脚本执行数据库迁移、编译静态资源并重新创建应用、Sidekiq 和 Nginx。若更换了 Elasticsearch 集群，应用启动后运行 `docker compose exec app bundle exec rake chewy:reset`，重建索引并验证中文搜索。迁移过对象存储时，核对新上传和历史文件下载后停止旧 MinIO 容器，保留旧数据备份以便回退。

v2.36.1 相对 v2.36.0 没有新增数据库迁移。从更早版本升级时仍需执行完整迁移。已有七牛对象继续按记录读取。如需继续向七牛写入，在 `app.local.env` 显式设置 `SETTING_STORAGE_WRITE=qiniu` 并保留七牛凭据。

### 将 MinIO 对象迁到 RustFS

1. 初始化独立的 `data/rustfs` 数据目录和新凭据。旧容器仍占用 9000、9001 时，可在 `.env` 设置 `RUSTFS_API_PORT=19000` 和 `RUSTFS_CONSOLE_PORT=19001`。运行 `docker compose up -d --wait rustfs` 并创建同名目标桶。
2. 通过 S3 API 逐桶复制对象，保留桶名和对象键。例如在配置好两个 S3 remote 后运行 `rclone copy minio:桶名 rustfs:桶名`。如使用版本历史、加密、对象锁或自定义桶策略，按原配置迁移并逐项核对。切换前暂停旧端写入、补齐最后的变更并抽查历史对象。 [RustFS rclone 集成](https://docs.rustfs.com/en/developer/integration/others/rclone)
3. 更新 `app.local.env` 中的 `SETTING_MINIO_ENDPOINT=http://rustfs:9000` 和应用的 RustFS 凭据。保留原来供浏览器使用的站点 `/s3` 地址及桶名，然后按上面的顺序完成 Skylark 升级。
