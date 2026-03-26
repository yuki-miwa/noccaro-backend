# Live Thread / Amazon IVS PoC

## Scope
- Space-scoped, time-scoped live thread
- One active live thread and one active live stream per space
- `primary_owner` can start / close thread and start / end stream
- Active members can watch and request IVS Chat token
- Chat messages are not persisted in Noccaro
- Fixed IVS Channel / Chat Room resources are used for the PoC

## Public API
- `GET /api/v1/spaces/{spaceId}/live-thread`
- `POST /api/v1/spaces/{spaceId}/live-thread/start`
- `POST /api/v1/spaces/{spaceId}/live-thread/close`
- `GET /api/v1/spaces/{spaceId}/live-stream`
- `POST /api/v1/spaces/{spaceId}/live-stream/start`
- `POST /api/v1/spaces/{spaceId}/live-stream/end`
- `POST /api/v1/spaces/{spaceId}/live-chat/token`

## System Admin API
- `GET /api/v1/system-admin/live-threads`
- `POST /api/v1/system-admin/spaces/{spaceId}/live-thread/force-close`
- `POST /api/v1/system-admin/spaces/{spaceId}/live-stream/force-end`

## Required Secrets
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `IVS_STREAM_KEY`

## Fixed IVS Settings
- `IVS_CHANNEL_ARN`
- `IVS_PLAYBACK_URL`
- `IVS_INGEST_ENDPOINT`
- `IVS_CHAT_ROOM_ARN`
- `IVS_CHAT_ROOM_ID`
- `IVS_CHAT_ENDPOINT`

## Notes
- Without `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`, `/live-chat/token` returns `LIVE_CHAT_UNAVAILABLE`.
- Without `IVS_STREAM_KEY`, `/live-stream/start` returns `LIVE_STREAM_UNAVAILABLE`.
- Force end / force close only update Noccaro domain state. They do not call AWS to terminate a broadcast session.
