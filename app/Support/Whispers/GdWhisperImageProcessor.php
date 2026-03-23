<?php

namespace App\Support\Whispers;

use App\Contracts\Whispers\WhisperImageProcessor;
use App\Exceptions\ApiException;
use GdImage;
use Illuminate\Http\UploadedFile;

class GdWhisperImageProcessor implements WhisperImageProcessor
{
    public function process(UploadedFile $file): ProcessedWhisperImage
    {
        if (! extension_loaded('gd')) {
            throw new ApiException('IMAGE_PROCESSING_UNAVAILABLE', '画像処理の準備ができていません。', 503);
        }

        $realPath = $file->getRealPath();
        $contents = $realPath ? @file_get_contents($realPath) : false;
        if ($contents === false) {
            throw new ApiException('VALIDATION_ERROR', '画像ファイルを読み込めませんでした。', 422, ['field' => 'image']);
        }

        $info = @getimagesizefromstring($contents);
        if (! is_array($info) || empty($info['mime'])) {
            throw new ApiException('VALIDATION_ERROR', '画像ファイルを確認してください。', 422, ['field' => 'image']);
        }

        $source = @imagecreatefromstring($contents);
        if (! $source instanceof GdImage) {
            throw new ApiException('VALIDATION_ERROR', '画像ファイルを読み込めませんでした。', 422, ['field' => 'image']);
        }

        try {
            $source = $this->applyOrientation($source, $file, $info['mime']);

            $original = $this->resizeToFit($source, (int) config('whisper_images.max_original_dimension', 1600));
            $preview = $this->resizeToFit($source, (int) config('whisper_images.preview_dimension', 960));
            $thumbnail = $this->resizeToFit($source, (int) config('whisper_images.thumbnail_dimension', 320));

            $originalBinary = $this->encodeJpeg($original, (int) config('whisper_images.jpeg_quality', 82));
            $previewBinary = $this->encodeJpeg($preview, (int) config('whisper_images.preview_quality', 80));
            $thumbnailBinary = $this->encodeJpeg($thumbnail, (int) config('whisper_images.thumbnail_quality', 72));

            return new ProcessedWhisperImage(
                originalBinary: $originalBinary,
                previewBinary: $previewBinary,
                thumbnailBinary: $thumbnailBinary,
                mimeType: 'image/jpeg',
                width: imagesx($original),
                height: imagesy($original),
                byteSize: strlen($originalBinary),
            );
        } finally {
            imagedestroy($source);
            if (isset($original) && $original instanceof GdImage) {
                imagedestroy($original);
            }
            if (isset($preview) && $preview instanceof GdImage) {
                imagedestroy($preview);
            }
            if (isset($thumbnail) && $thumbnail instanceof GdImage) {
                imagedestroy($thumbnail);
            }
        }
    }

    private function applyOrientation(GdImage $source, UploadedFile $file, string $mimeType): GdImage
    {
        if ($mimeType !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $source;
        }

        $realPath = $file->getRealPath();
        if (! $realPath) {
            return $source;
        }

        $exif = @exif_read_data($realPath);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($source, 180, 0) ?: $source,
            6 => imagerotate($source, -90, 0) ?: $source,
            8 => imagerotate($source, 90, 0) ?: $source,
            default => $source,
        };
    }

    private function resizeToFit(GdImage $source, int $maxDimension): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= $maxDimension && $height <= $maxDimension) {
            return $this->flattenToCanvas($source, $width, $height);
        }

        $scale = min($maxDimension / $width, $maxDimension / $height);
        $targetWidth = max((int) round($width * $scale), 1);
        $targetHeight = max((int) round($height * $scale), 1);

        return $this->flattenToCanvas($source, $targetWidth, $targetHeight);
    }

    private function flattenToCanvas(GdImage $source, int $targetWidth, int $targetHeight): GdImage
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        $background = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $background);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($source),
            imagesy($source),
        );

        return $canvas;
    }

    private function encodeJpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);
        $binary = ob_get_clean();

        if (! is_string($binary) || $binary === '') {
            throw new ApiException('IMAGE_PROCESSING_UNAVAILABLE', '画像の保存に失敗しました。', 500);
        }

        return $binary;
    }
}
