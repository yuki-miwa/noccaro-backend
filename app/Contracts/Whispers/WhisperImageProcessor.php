<?php

namespace App\Contracts\Whispers;

use App\Support\Whispers\ProcessedWhisperImage;
use Illuminate\Http\UploadedFile;

interface WhisperImageProcessor
{
    public function process(UploadedFile $file): ProcessedWhisperImage;
}
