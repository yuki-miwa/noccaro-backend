# Space Creation Request Extension

## Summary

Flutter app no longer creates spaces directly.
It submits a `space_creation_request`, and system admin reviews it.

## Public API

- `POST /api/v1/spaces/creation-requests`
- `GET /api/v1/spaces/creation-requests`
- `GET /api/v1/spaces/creation-requests/{requestId}`

### Request payload

```json
{
  "spaceName": "Noccaro Osaka",
  "spaceCode": "OSAKA2026",
  "joinPolicy": "approval_required"
}
```

### Resource shape

```json
{
  "id": "uuid",
  "spaceName": "Noccaro Osaka",
  "spaceCode": "OSAKA2026",
  "joinPolicy": "approval_required",
  "status": "pending",
  "requestType": "space_creation",
  "createdSpaceId": null,
  "rejectionVisibleUntil": null,
  "createdAt": "2026-03-21T12:34:56Z",
  "updatedAt": "2026-03-21T12:34:56Z"
}
```

### Visibility rules

List endpoint returns only:
- `pending`
- `rejected` while `rejectionVisibleUntil` is in the future

List endpoint does not return:
- `approved`
- expired `rejected`

Detail endpoint may return:
- `pending`
- `approved`
- `rejected` while still visible

## System Admin API

- `GET /api/v1/system-admin/space-creation-requests`
- `POST /api/v1/system-admin/space-creation-requests/{requestId}/approve`
- `POST /api/v1/system-admin/space-creation-requests/{requestId}/reject`

## Approval behavior

Approving a request:
1. creates the actual space
2. injects backend defaults
3. creates active `primary_owner` membership for the requester
4. marks the request as `approved`
5. stores `approved_space_id`

## Rejection behavior

Rejecting a request:
1. marks the request as `rejected`
2. stores `rejected_at`
3. stores `rejection_visible_until = rejected_at + 72h`
4. hides it from public list after that point

## Backend defaults for approved spaces

- `description`: `""`
- `maxOwnerCount`: `3`
- `whisperTtlMinutes`: `180`
- `whisperMaxLength`: `20`
- `locationGridMeters`: `120`
- `autoHideReportThreshold`: `5`
- `postLimitPerMinute`: `1`
- `postLimitPerTenMinutes`: `3`
- `locationJitterEnabled`: `true`

## Error codes

- `SPACE_CREATION_REQUEST_NOT_FOUND`
- `SPACE_CREATION_REQUEST_ALREADY_PENDING`
- `SPACE_CREATION_REQUEST_NOT_PENDING`
- `SPACE_CODE_ALREADY_TAKEN`
- `SPACE_CODE_ALREADY_RESERVED`
- `VALIDATION_ERROR`
- `FORBIDDEN`
