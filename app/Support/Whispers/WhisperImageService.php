<?php

namespace App\Support\Whispers;

use App\Contracts\Whispers\WhisperImageProcessor;
use App\Models\MapWhisper;
use App\Models\WhisperImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class WhisperImageService
{
    public function __construct(private readonly WhisperImageProcessor $processor) {}

    public function attachUploadedImage(MapWhisper $whisper, UploadedFile $file): WhisperImage
    {
        $processed = $this->processor->process($file);
        $disk = (string) config('whisper_images.disk', 'public');
        $directory = $this->directoryFor($whisper);
        $originalPath = $directory.'/original.jpg';
        $previewPath = $directory.'/preview.jpg';
        $thumbnailPath = $directory.'/thumbnail.jpg';

        $storage = Storage::disk($disk);

        try {
            if (! $storage->exists($directory)) {
                $storage->makeDirectory($directory);
            }

            $storage->put($originalPath, $processed->originalBinary);
            $storage->put($previewPath, $processed->previewBinary);
            $storage->put($thumbnailPath, $processed->thumbnailBinary);
        } catch (\Throwable $exception) {
            $storage->delete([$originalPath, $previewPath, $thumbnailPath]);
            throw $exception;
        }

        return WhisperImage::query()->updateOrCreate(
            ['whisper_id' => $whisper->id],
            [
                'disk' => $disk,
                'original_path' => $originalPath,
                'preview_path' => $previewPath,
                'thumbnail_path' => $thumbnailPath,
                'mime_type' => $processed->mimeType,
                'width' => $processed->width,
                'height' => $processed->height,
                'byte_size' => $processed->byteSize,
            ],
        );
    }

    public function purgeForWhisper(MapWhisper $whisper): void
    {
        $whisper->loadMissing('image');
        $image = $whisper->image;

        if (! $image) {
            return;
        }

        $this->purgeImageRecord($image);
    }

    public function purgeDirectoryByPublicId(string $whisperPublicId): void
    {
        Storage::disk((string) config('whisper_images.disk', 'local'))
            ->deleteDirectory('whispers/'.$whisperPublicId);
    }

    private function purgeImageRecord(WhisperImage $image): void
    {
        Storage::disk($image->disk)->delete([
            $image->original_path,
            $image->preview_path,
            $image->thumbnail_path,
        ]);

        Storage::disk($image->disk)->deleteDirectory(dirname($image->original_path));
        $image->delete();
    }

    private function directoryFor(MapWhisper $whisper): string
    {
        return 'whispers/'.$whisper->public_id;
    }
}
