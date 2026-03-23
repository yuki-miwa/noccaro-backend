<?php

namespace App\Support\Whispers;

final readonly class ProcessedWhisperImage
{
    public function __construct(
        public string $originalBinary,
        public string $previewBinary,
        public string $thumbnailBinary,
        public string $mimeType,
        public int $width,
        public int $height,
        public int $byteSize,
    ) {}
}
