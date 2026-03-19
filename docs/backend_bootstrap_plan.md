# Noccaro Backend Bootstrap Plan

## 現状

- `noccaro-app`: Flutter モバイルアプリ
- `noccaro-web`: React / Vite の owner admin + system admin 画面
- `noccaro-backend`: 今回新規作成した Laravel 12 backend

この backend は、承認済み仕様書と DDL を業務ルールの正、`docs/backend_api_spec.md` を API 契約の正として実装する。

## 置き方

- リポジトリは `noccaro-backend` として分離する
- 本番では Lightsail 1 台に `web + backend + postgres + redis` を同居可能
- 開発時は frontend と backend を分離して起動する

## 現時点のギャップ

Laravel backend はこれまで存在していなかったため、以下が未実装だった。

1. Bearer token 認証 API
2. モバイル公開 API
3. owner admin が接続する実 admin API
4. PostgreSQL / Redis 前提の backend 設定
5. seed / feature test / error envelope

## 今回の実装方針

まず public P0 を優先して土台を作る。

1. Laravel 12 + Sanctum の API 基盤
2. DDL に沿った中核テーブル
3. public API の P0 実装
4. seed と feature test
5. owner admin API を次フェーズで追加

## 実行環境方針

- 本番 DB: PostgreSQL
- 本番 cache / queue: Redis
- テスト: SQLite in-memory
- 認証: Laravel Sanctum の personal access token を Bearer token として利用

## このフェーズで目指す到達点

- モバイルから register / login / logout / refresh / me が呼べる
- joined spaces / membership / join が使える
- post list / detail / reaction が使える
- whisper list / create / report が使える
- notification settings / device register / unregister が使える
- API error envelope を統一できる

