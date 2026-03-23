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
- `stat -c '%U %G %a %n' /var/www/noccaro-backend/storage/app/private /var/www/noccaro-backend/storage/app/private/whispers`

## Whisper 画像保存の補足

- Whisper 画像は `local` disk の private storage に保存する
- `storage/app/private` と `storage/app/private/whispers` は `php-fpm` から書き込み可能であること
- Lightsail では `ubuntu:www-data` + `2775` を基準にしておくと再発しにくい
- 初回セットアップ時は次を実行する

```bash
sudo usermod -aG www-data ubuntu
sudo mkdir -p /var/www/noccaro-backend/storage/app/private/whispers
sudo chown -R ubuntu:www-data /var/www/noccaro-backend/storage/app/private
sudo find /var/www/noccaro-backend/storage/app/private -type d -exec chmod 2775 {} \;
sudo find /var/www/noccaro-backend/storage/app/private -type f -exec chmod 664 {} \;
```

- scheduler をユーザー crontab で回す場合は `www-data` グループで実行する

```bash
crontab -l | { cat; echo "* * * * * cd /var/www/noccaro-backend && /usr/bin/sg www-data -c '/usr/bin/php artisan schedule:run' >> /dev/null 2>&1"; } | crontab -
```

## 補足

- API の未認証応答は JSON envelope を返すようにする
- public IP の変動を避けるため、Lightsail では Static IP を付ける
- 本番の認証情報や DB パスワードは repo に含めない
