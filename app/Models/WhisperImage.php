<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhisperImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'whisper_id',
        'disk',
        'original_path',
        'preview_path',
        'thumbnail_path',
        'mime_type',
        'width',
        'height',
        'byte_size',
    ];

    public function whisper(): BelongsTo
    {
        return $this->belongsTo(MapWhisper::class, 'whisper_id');
    }
}
