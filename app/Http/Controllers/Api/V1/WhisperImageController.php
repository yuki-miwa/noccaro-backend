<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\MapWhisper;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WhisperImageController
{
    public function show(MapWhisper $whisper, string $variant): StreamedResponse
    {
        if (! in_array($variant, ['original', 'preview', 'thumbnail'], true)) {
            throw new ApiException('RESOURCE_NOT_FOUND', '対象画像が見つかりません。', 404);
        }

        $whisper->loadMissing('image');
        $image = $whisper->image;

        if (! $image || $whisper->status !== 'active' || ! $whisper->expires_at?->isFuture()) {
            throw new ApiException('RESOURCE_NOT_FOUND', '対象画像が見つかりません。', 404);
        }

        $path = match ($variant) {
            'original' => $image->original_path,
            'preview' => $image->preview_path,
            'thumbnail' => $image->thumbnail_path,
        };

        $storage = Storage::disk($image->disk);
        if (! $storage->exists($path)) {
            throw new ApiException('RESOURCE_NOT_FOUND', '対象画像が見つかりません。', 404);
        }

        $stream = $storage->readStream($path);
        if (! is_resource($stream)) {
            throw new ApiException('RESOURCE_NOT_FOUND', '対象画像が見つかりません。', 404);
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $image->mime_type,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
