# Whisper Image Local Storage Design

## Scope

- `body required`
- `image optional`
- `1 whisper = 画像 1 枚まで`
- local storage (`local` disk / private storage) から開始し、将来は `disk + path` を維持したまま S3 へ移行可能にする

## Storage model

`whisper_images` テーブルで whisper に 1:1 の画像メタデータを持つ。

保持項目:
- `disk`
- `original_path`
- `preview_path`
- `thumbnail_path`
- `mime_type`
- `width`
- `height`
- `byte_size`

運用メモ:
- live では `local` disk の root が `storage/app/private` になる
- `php-fpm` の実行ユーザーが `storage/app/private` と `storage/app/private/whispers` に書き込めること
- Lightsail 1 台構成では `ubuntu:www-data` + directory mode `2775` を基準にする
- scheduler / queue から purge するため、CLI 実行ユーザーも `www-data` グループで動かす

## Processing

- 受け付け: `jpeg`, `png`, `webp`
- 保存時に JPEG へ再エンコード
- EXIF は再エンコード時に除去
- 長辺 1600px に収まる original を作成
- preview 960px, thumbnail 320px を生成
- public API には signed image URL を返し、ファイル自体は private storage に置く

## Lifecycle

- `hidden_by_report`: 画像も purge
- `removed_by_owner`: 画像も purge
- `removed_by_system`: 画像も purge
- `expired`: scheduler / command で画像も purge

## Public payload

Whisper resource に `image` を追加する。

- `originalUrl`
- `previewUrl`
- `thumbnailUrl`
- `mimeType`
- `width`
- `height`
- `byteSize`
