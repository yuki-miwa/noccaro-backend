<?php

namespace Tests\Feature;

use App\Contracts\Whispers\WhisperImageProcessor;
use App\Models\MapWhisper;
use App\Models\Space;
use App\Models\User;
use App\Support\Whispers\ProcessedWhisperImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhisperImageTest extends TestCase
{
    use RefreshDatabase;

    private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WlTH0cAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('whisper_images.disk', 'local');

        $this->app->bind(WhisperImageProcessor::class, fn () => new class implements WhisperImageProcessor
        {
            public function process(UploadedFile $file): ProcessedWhisperImage
            {
                return new ProcessedWhisperImage(
                    originalBinary: 'original-jpeg-binary',
                    previewBinary: 'preview-jpeg-binary',
                    thumbnailBinary: 'thumbnail-jpeg-binary',
                    mimeType: 'image/jpeg',
                    width: 1280,
                    height: 960,
                    byteSize: strlen('original-jpeg-binary'),
                );
            }
        });
    }

    public function test_active_member_can_create_whisper_with_image_and_access_signed_variants(): void
    {
        [$space, $whisperResponse] = $this->createWhisperWithImage();
        $whisperResponse->assertCreated();

        $whisperId = $whisperResponse->json('data.whisper.id');
        $whisper = MapWhisper::query()->where('public_id', $whisperId)->firstOrFail();

        Storage::disk('local')->assertExists('whispers/'.$whisper->public_id.'/original.jpg');
        Storage::disk('local')->assertExists('whispers/'.$whisper->public_id.'/preview.jpg');
        Storage::disk('local')->assertExists('whispers/'.$whisper->public_id.'/thumbnail.jpg');

        $whisperResponse
            ->assertJsonPath('data.whisper.spaceId', $space->public_id)
            ->assertJsonPath('data.whisper.image.mimeType', 'image/jpeg')
            ->assertJsonPath('data.whisper.image.width', 1280)
            ->assertJsonPath('data.whisper.image.height', 960);

        $previewResponse = $this->get($this->relativePathFromSignedUrl($whisperResponse->json('data.whisper.image.previewUrl')));
        $previewResponse->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('preview-jpeg-binary', $previewResponse->streamedContent());

        $thumbnailResponse = $this->get($this->relativePathFromSignedUrl($whisperResponse->json('data.whisper.image.thumbnailUrl')));
        $thumbnailResponse->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('thumbnail-jpeg-binary', $thumbnailResponse->streamedContent());
    }

    public function test_auto_hidden_whisper_purges_its_image_files_and_record(): void
    {
        [$space, $whisperResponse] = $this->createWhisperWithImage();
        $whisperResponse->assertCreated();
        $space->update(['whisper_auto_hide_report_threshold' => 1]);

        $whisperId = $whisperResponse->json('data.whisper.id');
        $previewUrl = $whisperResponse->json('data.whisper.image.previewUrl');
        $whisper = MapWhisper::query()->where('public_id', $whisperId)->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/whispers/'.$whisper->public_id.'/report', [
            'reasonType' => 'spam',
        ])
            ->assertCreated()
            ->assertJsonPath('data.whisper.status', 'hidden_by_report');

        $this->assertDatabaseMissing('whisper_images', [
            'whisper_id' => $whisper->id,
        ]);
        Storage::disk('local')->assertMissing('whispers/'.$whisper->public_id.'/original.jpg');
        Storage::disk('local')->assertMissing('whispers/'.$whisper->public_id.'/preview.jpg');
        Storage::disk('local')->assertMissing('whispers/'.$whisper->public_id.'/thumbnail.jpg');

        $this->get($this->relativePathFromSignedUrl($previewUrl))->assertNotFound();
    }

    public function test_owner_remove_purges_whisper_image_files_and_record(): void
    {
        [, $whisperResponse] = $this->createWhisperWithImage();
        $whisperResponse->assertCreated();
        $whisper = MapWhisper::query()->where('public_id', $whisperResponse->json('data.whisper.id'))->firstOrFail();
        $owner = User::query()->where('email', 'owner@noccaro.local')->firstOrFail();

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/admin/whispers/'.$whisper->public_id.'/remove', [
            'reason' => '画像付き whisper の削除確認',
        ])
            ->assertOk()
            ->assertJsonPath('data.whisper.status', 'removed_by_owner')
            ->assertJsonPath('data.whisper.image', null);

        $this->assertDatabaseMissing('whisper_images', [
            'whisper_id' => $whisper->id,
        ]);
        Storage::disk('local')->assertMissing('whispers/'.$whisper->public_id.'/preview.jpg');
    }

    public function test_expire_whispers_command_purges_image_files_and_marks_expired(): void
    {
        [, $whisperResponse] = $this->createWhisperWithImage();
        $whisperResponse->assertCreated();
        $whisper = MapWhisper::query()->where('public_id', $whisperResponse->json('data.whisper.id'))->firstOrFail();
        $previewUrl = $whisperResponse->json('data.whisper.image.previewUrl');

        $whisper->forceFill([
            'expires_at' => now()->subMinute(),
            'status' => 'active',
        ])->save();

        Artisan::call('noccaro:expire-whispers');

        $whisper->refresh();
        $this->assertSame('expired', $whisper->status);
        $this->assertDatabaseMissing('whisper_images', [
            'whisper_id' => $whisper->id,
        ]);
        Storage::disk('local')->assertMissing('whispers/'.$whisper->public_id.'/original.jpg');
        $this->get($this->relativePathFromSignedUrl($previewUrl))->assertNotFound();
    }

    /**
     * @return array{0: Space, 1: TestResponse}
     */
    private function createWhisperWithImage(): array
    {
        $this->seed();

        $space = Space::query()->where('space_code', 'NOC2026')->firstOrFail();
        $space->update([
            'whisper_rate_limit_per_minute' => 10,
            'whisper_rate_limit_per_10min' => 20,
        ]);
        $guest = User::query()->where('email', 'guest@noccaro.local')->firstOrFail();

        Sanctum::actingAs($guest);

        $response = $this->post(
            '/api/v1/spaces/'.$space->public_id.'/whispers',
            [
                'body' => '画像つき whisper',
                'exactLat' => 35.6809591,
                'exactLng' => 139.7673068,
                'image' => $this->fakePngUpload(),
            ],
            [
                'Accept' => 'application/json',
            ],
        );

        return [$space, $response];
    }

    private function fakePngUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'whisper.png',
            base64_decode(self::TINY_PNG_BASE64, true) ?: '',
        );
    }

    private function relativePathFromSignedUrl(string $signedUrl): string
    {
        $parts = parse_url($signedUrl);
        $path = $parts['path'] ?? '/';

        if (! empty($parts['query'])) {
            return $path.'?'.$parts['query'];
        }

        return $path;
    }
}
