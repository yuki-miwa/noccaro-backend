# Noccaro Lightsail Single Node Deploy

Noccaro の MVP を Amazon Lightsail 1 台で動かす前提のメモです。

## 想定構成

- OS: Ubuntu 24.04 LTS
- Web: Nginx から `/var/www/noccaro-web/dist` を静的配信
- API: `/api` を Laravel (`/var/www/noccaro-backend`) にルーティング
- DB: PostgreSQL
- Cache / Queue: Redis + systemd queue worker
- TLS: certbot + Let's Encrypt

## 配置先

- frontend repo: `/var/www/noccaro-web`
- backend repo: `/var/www/noccaro-backend`
- nginx site: `/etc/nginx/sites-available/noccaro`
- queue worker: `/etc/systemd/system/noccaro-queue.service`

## 必要サービス

- `nginx`
- `php8.4-fpm`
- `postgresql`
- `redis-server`
- `noccaro-queue.service`

## 公開経路

- `https://<domain>/` : React admin frontend
- `https://<domain>/api/...` : Laravel API
- `https://<domain>/storage/...` : Laravel public storage

## 初期セットアップの要点

1. `ufw` で `OpenSSH`, `Nginx Full` のみ開放する
2. swap を追加して 2GB メモリ環境を安定化する
3. PostgreSQL に `noccaro` database / role を作成する
4. backend の `.env` で `APP_ENV=production`, `APP_DEBUG=false` を設定する
5. `php artisan migrate --force` を実行する
6. frontend は `VITE_API_MODE=real` で build する
7. certbot で HTTPS を有効化する

## デプロイ時チェック

- `systemctl is-active nginx postgresql redis-server noccaro-queue`
- `php artisan migrate:status`
- `php artisan route:list --path=api`
- `curl -H 'Accept: application/json' https://<domain>/api/v1/me`

## 補足

- API の未認証応答は JSON envelope を返すようにする
- public IP の変動を避けるため、Lightsail では Static IP を付ける
- 本番の認証情報や DB パスワードは repo に含めない
