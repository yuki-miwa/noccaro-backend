<?php

return [
    'disk' => env('WHISPER_IMAGE_DISK', 'local'),
    'max_upload_kb' => (int) env('WHISPER_IMAGE_MAX_UPLOAD_KB', 8192),
    'max_original_dimension' => (int) env('WHISPER_IMAGE_MAX_ORIGINAL_DIMENSION', 1600),
    'preview_dimension' => (int) env('WHISPER_IMAGE_PREVIEW_DIMENSION', 960),
    'thumbnail_dimension' => (int) env('WHISPER_IMAGE_THUMB_DIMENSION', 320),
    'jpeg_quality' => (int) env('WHISPER_IMAGE_JPEG_QUALITY', 82),
    'preview_quality' => (int) env('WHISPER_IMAGE_PREVIEW_QUALITY', 80),
    'thumbnail_quality' => (int) env('WHISPER_IMAGE_THUMBNAIL_QUALITY', 72),
];
