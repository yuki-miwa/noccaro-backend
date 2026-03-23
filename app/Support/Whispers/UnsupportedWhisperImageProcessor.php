<?php

namespace App\Support\Whispers;

use App\Contracts\Whispers\WhisperImageProcessor;
use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

class UnsupportedWhisperImageProcessor implements WhisperImageProcessor
{
    public function process(UploadedFile $file): ProcessedWhisperImage
    {
        throw new ApiException(
            'IMAGE_PROCESSING_UNAVAILABLE',
            '画像処理の準備ができていません。しばらくしてから再度お試しください。',
            503,
        );
    }
}
