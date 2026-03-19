# noccaro-backend

Noccaro の Laravel backend です。Flutter モバイルアプリと Web 管理画面が接続する API を提供します。

## 現在の実装状況

このフェーズでは public P0 を優先して実装しています。

- Laravel 12
- Sanctum Bearer token 認証
- SQLite in-memory での feature test
- PostgreSQL / Redis を本番想定とした `.env.example`
- public API の最小実装

owner admin API / system admin API は後続フェーズです。

## 実装済み public API

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/refresh`
- `GET /api/v1/me`
- `GET /api/v1/me/notification-settings`
- `PUT /api/v1/me/notification-settings`
- `GET /api/v1/spaces/joined`
- `GET /api/v1/spaces/{spaceId}`
- `GET /api/v1/spaces/{spaceId}/membership`
- `POST /api/v1/spaces/join`
- `GET /api/v1/spaces/{spaceId}/posts`
- `GET /api/v1/posts/{postId}`
- `PUT /api/v1/posts/{postId}/reaction`
- `GET /api/v1/spaces/{spaceId}/whispers`
- `POST /api/v1/spaces/{spaceId}/whispers`
- `POST /api/v1/whispers/{whisperId}/report`
- `POST /api/v1/devices/register`
- `POST /api/v1/devices/unregister`

## 主要ドキュメント

- [docs/backend_bootstrap_plan.md](docs/backend_bootstrap_plan.md)
- [docs/p0_surface_map.md](docs/p0_surface_map.md)

## ローカル実行

この環境では `php` / `composer` が未インストールでも動かせるように、Docker wrapper を同梱しています。

前提:
- Docker daemon が起動していること
- 初回は `colima start` などで Docker runtime を立ち上げること

### サーバー起動

```bash
cd /Users/yuki.miwa/Documents/git/noccaro-backend
./bin/serve
```

- API URL: `http://127.0.0.1:8000/api/v1`

### migration / seed

```bash
./bin/artisan migrate:fresh --seed
```

### test

```bash
./bin/test
```

## 開発用 seed アカウント

- owner: `owner@noccaro.local` / `password123`
- guest: `guest@noccaro.local` / `password123`
- pending: `pending@noccaro.local` / `password123`
- sample space code: `NOC2026`

## 設計メモ

- 業務ルールは承認済み仕様書と DDL を優先
- API 契約は `noccaro-app/docs/backend_api_spec.md` を優先
- public ID は UUID ベースの `public_id` を返却
- エラーは `error.code`, `error.message`, `error.details` の envelope で返却
- whisper の表示座標は server-side rounding + jitter で生成

## 既知事項

- owner admin API / system admin API は未実装
- PostgreSQL 専用の partial index / trigger / audit log 最適化は未着手
- queue / push delivery 実装は未着手
- 本番運用では PostgreSQL / Redis を別途用意する必要があります

