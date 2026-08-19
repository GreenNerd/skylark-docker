# Skylark Docker
Docker deployment of [Skylark](https://github.com/GreenNerd/skylark).

核心服务：Postgres 16、Redis 7.4、Elasticsearch 8.19.20（IK）、MinIO。已无 memcached（2.34+ 镜像使用 Redis cache）。

## Installation
  - git
  - [docker](https://docs.docker.com/install)
  - [docker-compose](https://docs.docker.com/compose/install)

## 设置 Docker

#### 1、Change docker mirror
add the below json into `/etc/docker/daemon.json`, and run `sudo service docker restart` to restart docker.
```json
  {
    "registry-mirrors": ["https://registry.docker-cn.com"]
  }
```

#### 2、自启动
[文档](https://docs.docker.com/install/linux/linux-postinstall/#configure-docker-to-start-on-boot)
`sudo systemctl enable docker`


## Start Deploying

#### 1、Clone repo
Create a `/var/skylark` folder, clone the Official Skylark Docker Image into it:

```bash
mkdir /var/skylark
git clone https://github.com/GreenNerd/skylark-docker.git /var/skylark
cd /var/skylark
```

#### 2、Configuration
- `touch app.local.env`
- 根据 `app.default.env`，在 `app.local.env` 里配置站点设置
- 复制 `minio.local.env.example` 为 `minio.local.env`，填写 `MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD`
- 在 `app.local.env` 填写 `SETTING_MINIO_*`；与 MinIO 账号一致后可将 `SETTING_STORAGE_WRITE=minio`
- Rails 7.2+ 需要 `SECRET_KEY_BASE`，与 `SECRETS_KEY_BASE` 同值
- Puma 使用镜像内 `config/puma.rb`，环境变量为 `RAILS_MAX_THREADS` / `WEB_CONCURRENCY` / `PORT` / `PIDFILE` / `BIND_HOST`（不再使用 `PUMA_*`）
- 在 `.env` 设置部署版本号，例：`SLP_VERSION=2.34.2-88f8dfd`

`2.34.2-88f8dfd` 镜像内没有 `config/puma.rb`。去掉宿主机 `puma.rb` 挂载后，必须换成已自带该文件的更新 tag（Puma 8 收口之后）。

生产镜像从阿里云拉取，不要 `docker-compose build`。

#### 3、Login aliyun docker repo
```bash
./scripts/login
```

#### 4、Initialization
`./scripts/install`（pull 镜像、建库、precompile assets）

#### 5、Start server
`./scripts/start`

#### 6、创建一个`Namespace`
- `docker-compose exec app bundle exec rails console`，进入Rails console
- `Namespace.create name: '空间名字'`


#### 7、Logrotate
`./scripts/logrotate`，日志切割，旧日志存放在`./old_log`，可以把`old_log`软连接到另一个磁盘

## 从旧版本升级

Postgres / Redis / Elasticsearch **大版本不能只改镜像 tag**，需要迁数据或换数据目录。

Elasticsearch 8.19.20 不能复用 5.x / 7.x 的 `./data/elasticsearch`：换目录或清空后，在应用侧执行 `bundle exec rake chewy:reset`（全量重建，需人工确认；`./scripts/update` 不会自动跑）。

升应用版本：改 `.env` 的 `SLP_VERSION` 后执行 `./scripts/update`（pull、migrate、强制 precompile、recreate app/web/sidekiq、nginx reload）。
