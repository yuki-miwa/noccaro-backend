# P0 Surface Map

## Migration / Model

- `users`
  - `public_id`, `display_name`, `status`, `last_login_at`, `notifications_enabled`
- `user_push_devices`
- `spaces`
- `space_memberships`
- `space_posts`
- `space_post_reactions`
- `map_whispers`
- `content_reports`

## Public API

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

## Controller 責務

- `AuthController`
  - register, login, logout, refresh
- `MeController`
  - me, notification settings read / write
- `SpaceController`
  - joined, show, membership, join
- `PostController`
  - list, detail, reaction toggle
- `WhisperController`
  - list, create, report
- `DeviceController`
  - register, unregister

## 後続フェーズ

- owner admin API
- system admin API
- moderation audit log の詳細化
- PostgreSQL 専用 index / trigger 最適化
- queue / notification 配信

