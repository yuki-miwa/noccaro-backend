# Space Settings Runtime Extension

## Goal

public API の `space` object に runtime settings を含め、Flutter app が space ごとの設定値を UI / 文言 / 入力制限へ追従できるようにする。

## Affected endpoints

- `GET /api/v1/spaces/joined`
- `POST /api/v1/spaces/join`
- `GET /api/v1/spaces/{spaceId}`

## Space object fields

既存 field に加えて、以下を安定 field として返す。

- `description`
- `status`
- `maxOwnerCount`
- `whisperTtlMinutes`
- `whisperMaxLength`
- `locationGridMeters`
- `locationJitterEnabled`
- `autoHideReportThreshold`
- `postLimitPerMinute`
- `postLimitPerTenMinutes`
- `createdAt`

後方互換のため、既存 alias も当面維持する。

- `whisperAutoHideReportThreshold`
- `whisperRateLimitPerMinute`
- `whisperRateLimitPer10Min`

## Behavioral note

Whisper 作成時の server-side 文字数制限は `space.whisper_max_length` を authoritative とする。public API で返す `whisperMaxLength` と server validation の値を一致させる。
