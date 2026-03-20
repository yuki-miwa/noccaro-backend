# Posts / Notices Extension Draft

## Overview

Flutter 側の「お知らせ」再設計に合わせて、既存 `posts` resource を拡張する。
後方互換のため、`GET /api/v1/spaces/{spaceId}/posts` は `category` 未指定時に従来どおり `owner` のみを返す。

採用した設計:

- `space_posts` に `category` を追加
  - `owner`
  - `operation`
  - `personal`
- personal 対象者は `space_post_deliveries` で管理
- 既読状態は `space_post_reads` で `user_id + post_id` 単位に保持
- operation は現時点では `space` 文脈に閉じた運営お知らせとして扱う
- owner admin API は `owner` のみを扱う
- `operation` / `personal` は system admin API で管理する

## Public API changes

### Extended post object

```json
{
  "id": "uuid",
  "spaceId": "uuid",
  "authorMembershipId": "uuid",
  "category": "owner",
  "title": "お知らせ",
  "body": "本文",
  "status": "published",
  "notifyMembers": true,
  "publishedAt": "2026-03-19T12:00:00Z",
  "visibleFrom": null,
  "visibleTo": null,
  "reactionCount": 1,
  "reactedByMe": true,
  "isRead": false,
  "readAt": null,
  "createdAt": "2026-03-19T11:00:00Z",
  "updatedAt": "2026-03-19T12:00:00Z"
}
```

### `GET /api/v1/spaces/{spaceId}/posts`

Query:

- `category` optional: `owner | operation | personal`
- `limit` optional

Behavior:

- `category` 未指定時は `owner` のみ返す
- `operation` は active member 全員が閲覧可能
- `personal` は対象ユーザーのみ返す
- 並び順は `publishedAt desc`

### `GET /api/v1/posts/{postId}`

Response の `post` に以下が追加される:

- `category`
- `isRead`
- `readAt`

Behavior:

- `personal` は対象ユーザー以外には `404 RESOURCE_NOT_FOUND`

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

既存 owner admin API は維持しつつ、`owner` カテゴリのみ扱う。

- `GET /api/v1/admin/spaces/{spaceId}/posts?category=owner`
- `POST /api/v1/admin/spaces/{spaceId}/posts`
  - `category` は省略可、実質 `owner` 固定
- `PATCH /api/v1/admin/posts/{postId}`
- `POST /api/v1/admin/posts/{postId}/publish`
- `POST /api/v1/admin/posts/{postId}/archive`
- `DELETE /api/v1/admin/posts/{postId}`

`operation` / `personal` を owner admin API に投げた場合は受け付けない。

### System admin

system admin で `operation` / `personal` を扱う。

- `GET /api/v1/system-admin/spaces/{spaceId}/posts?category=all|owner|operation|personal`
- `POST /api/v1/system-admin/spaces/{spaceId}/posts`
- `PATCH /api/v1/system-admin/posts/{postId}`
- `POST /api/v1/system-admin/posts/{postId}/publish`
- `POST /api/v1/system-admin/posts/{postId}/archive`
- `DELETE /api/v1/system-admin/posts/{postId}`

Create request:

```json
{
  "category": "personal",
  "title": "あなた宛のお知らせ",
  "body": "本文",
  "status": "draft",
  "notifyMembers": false,
  "visibleFrom": null,
  "visibleTo": null,
  "recipientUserId": "uuid"
}
```

Rules:

- `personal` の場合は `recipientUserId` 必須
- `recipientUserId` はその space の active member である必要がある
- `personal` は `notifyMembers=true` 不可

## Compatibility notes

- 既存 Flutter クライアントは `category` 未指定一覧取得のままでも壊れない
- `post` object に新規 field が増えるが、既存 field は維持する
- `reaction` API の契約変更はない

## Future extensions

- category ごとの unread count
- truly global operation notices
- personal notice の push delivery 連携
