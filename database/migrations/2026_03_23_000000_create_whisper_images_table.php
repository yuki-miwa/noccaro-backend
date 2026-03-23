<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whisper_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whisper_id')->unique()->constrained('map_whispers')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('original_path', 255);
            $table->string('preview_path', 255);
            $table->string('thumbnail_path', 255);
            $table->string('mime_type', 80);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('byte_size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whisper_images');
    }
};
