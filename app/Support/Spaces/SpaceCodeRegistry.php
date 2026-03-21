<?php

namespace App\Support\Spaces;

use App\Exceptions\ApiException;
use App\Models\Space;
use App\Models\SpaceCreationRequest;

class SpaceCodeRegistry
{
    public function normalize(string $spaceCode): string
    {
        return strtoupper(trim($spaceCode));
    }

    public function ensureAvailableForCreationRequest(string $spaceCode): void
    {
        $normalized = $this->normalize($spaceCode);

        if ($this->spaceExists($normalized)) {
            throw new ApiException('SPACE_CODE_ALREADY_TAKEN', 'このスペースコードはすでに使用されています。', 409, [
                'field' => 'spaceCode',
            ]);
        }

        if ($this->hasPendingCreationRequest($normalized)) {
            throw new ApiException('SPACE_CODE_ALREADY_RESERVED', 'このスペースコードは申請中のため利用できません。', 409, [
                'field' => 'spaceCode',
            ]);
        }
    }

    public function ensureAvailableForSpace(string $spaceCode, ?int $ignorePendingRequestId = null, ?int $ignoreSpaceId = null): void
    {
        $normalized = $this->normalize($spaceCode);

        if ($this->spaceExists($normalized, $ignoreSpaceId)) {
            throw new ApiException('SPACE_CODE_ALREADY_TAKEN', 'このスペースコードはすでに使用されています。', 409, [
                'field' => 'spaceCode',
            ]);
        }

        if ($this->hasPendingCreationRequest($normalized, $ignorePendingRequestId)) {
            throw new ApiException('SPACE_CODE_ALREADY_RESERVED', 'このスペースコードは申請中のため利用できません。', 409, [
                'field' => 'spaceCode',
            ]);
        }
    }

    private function spaceExists(string $normalized, ?int $ignoreSpaceId = null): bool
    {
        return Space::query()
            ->when($ignoreSpaceId !== null, fn ($query) => $query->whereKeyNot($ignoreSpaceId))
            ->where('space_code', $normalized)
            ->exists();
    }

    private function hasPendingCreationRequest(string $normalized, ?int $ignorePendingRequestId = null): bool
    {
        return SpaceCreationRequest::query()
            ->when($ignorePendingRequestId !== null, fn ($query) => $query->whereKeyNot($ignorePendingRequestId))
            ->where('requested_space_code', $normalized)
            ->where('status', 'pending')
            ->exists();
    }
}
