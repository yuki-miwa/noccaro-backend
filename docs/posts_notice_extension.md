# Posts / Notices Extension Draft

## Overview

Flutter 側の「お知らせ」再設計に合わせて、既存 `posts` resource を拡張する。
前回案の `personal` category は廃止し、`owner` / `operation` の2カテゴリ + `audienceType` で表現する。

後方互換のため、`GET /api/v1/spaces/{spaceId}/posts` は `category` 未指定時に従来どおり `owner` のみを返す。

採用した設計:

- `space_posts.category`
  - `owner`
  - `operation`
- `space_posts.audience_type`
  - `all_members`
  - `targeted_users`
- targeted 配信対象は `space_post_deliveries` で管理
- 既読状態は `space_post_reads` で `user_id + post_id` 単位に保持
- public API では current user に見えてよい記事だけ返す
- `targetedToMe=true` は `audienceType=targeted_users` かつ current user が recipient のときのみ返る
- owner admin API は `owner` category のみ作成・管理する
- system admin API は `operation` category のみ作成・管理する
- owner / operation のどちらでも `targeted_users` を使える

## Data migration

前回案からの差し替え方針:

- 既存 `personal` post は migration で `operation + targeted_users` に変換
- `space_post_deliveries` と `space_post_reads` はそのまま継続利用

## Public API changes

### Extended post object

```json
{
  "id": "uuid",
  "spaceId": "uuid",
  "authorMembershipId": "uuid",
  "category": "owner",
  "audienceType": "targeted_users",
  "title": "お知らせ",
  "body": "本文",
  "status": "published",
  "notifyMembers": false,
  "publishedAt": "2026-03-19T12:00:00Z",
  "visibleFrom": null,
  "visibleTo": null,
  "reactionCount": 1,
  "reactedByMe": true,
  "isRead": false,
  "readAt": null,
  "targetedToMe": true,
  "createdAt": "2026-03-19T11:00:00Z",
  "updatedAt": "2026-03-19T12:00:00Z"
}
```

### `GET /api/v1/spaces/{spaceId}/posts`

Query:

- `category` optional: `owner | operation`
- `limit` optional

Behavior:

- `category` 未指定時は `owner` のみ返す
- `all_members` は active member 全員が閲覧可能
- `targeted_users` は recipient のみ返す
- 並び順は `publishedAt desc`
- `personal` category は public API から廃止

### `GET /api/v1/posts/{postId}`

Response の `post` に以下が追加される:

- `category`
- `audienceType`
- `isRead`
- `readAt`
- `targetedToMe`

Behavior:

- current user に見えてよい記事だけ返す
- recipient 以外の targeted post は `404 RESOURCE_NOT_FOUND`

### `POST /api/v1/posts/{postId}/read`

Purpose:

- 記事既読化

Behavior:

- idempotent
- 初回既読時のみ `readAt` を確定
- 2回目以降は同じ `readAt` を返す

Response:

```json
{
  "data": {
    "postId": "uuid",
    "isRead": true,
    "readAt": "2026-03-20T01:23:45Z"
  }
}
```

## Admin API changes

### Owner admin

owner admin は `owner` category のみ扱う。

- `GET /api/v1/admin/spaces/{spaceId}/posts?category=owner`
- `POST /api/v1/admin/spaces/{spaceId}/posts`
- `PATCH /api/v1/admin/posts/{postId}`
- `POST /api/v1/admin/posts/{postId}/publish`
- `POST /api/v1/admin/posts/{postId}/archive`
- `DELETE /api/v1/admin/posts/{postId}`

Create / update request:

```json
{
  "category": "owner",
  "audienceType": "targeted_users",
  "recipientUserIds": ["uuid-1", "uuid-2"],
  "title": "お知らせ",
  "body": "本文",
  "status": "draft",
  "notifyMembers": false,
  "visibleFrom": null,
  "visibleTo": null
}
```

Rules:

- `audienceType` omitted は `all_members`
- `targeted_users` の場合は `recipientUserIds` 必須
- recipient はその space の active member である必要がある
- `targeted_users + notifyMembers=true` も許可し、recipient のみへ Push を送れる
- `operation` を owner admin API に投げた場合は拒否

### System admin

system admin は `operation` category のみ扱う。

- `GET /api/v1/system-admin/spaces/{spaceId}/posts?category=all|owner|operation`
- `POST /api/v1/system-admin/spaces/{spaceId}/posts`
- `PATCH /api/v1/system-admin/posts/{postId}`
- `POST /api/v1/system-admin/posts/{postId}/publish`
- `POST /api/v1/system-admin/posts/{postId}/archive`
- `DELETE /api/v1/system-admin/posts/{postId}`

Create / update request:

```json
{
  "category": "operation",
  "audienceType": "targeted_users",
  "recipientUserIds": ["uuid-1", "uuid-2"],
  "title": "運営からのお知らせ",
  "body": "本文",
  "status": "draft",
  "notifyMembers": false,
  "visibleFrom": null,
  "visibleTo": null
}
```

Rules:

- `category` は `operation` 固定
- `targeted_users` の場合は `recipientUserIds` 必須
- recipient はその space の active member である必要がある
- `targeted_users + notifyMembers=true` も許可し、recipient のみへ Push を送れる

## Compatibility notes

- 既存 Flutter クライアントは `category` 未指定一覧取得のままでも壊れない
- `post` object に新規 field が増えるが、既存 field は維持する
- `reaction` API の契約変更はない
- `personal` category に依存する新規 client 実装は今回から非推奨

## Future extensions

- category ごとの unread count
- targeted post 用の push delivery
- global operation notices
