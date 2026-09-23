<?php

namespace Tests\Feature;

use App\Rules\ImageGuideline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Upload size caps were raised to a 1 MB floor (were 250–500 KB). A normal photo/export now
 * passes; only size changed — aspect ratio and min width are still enforced.
 */
class ImageUploadSizeTest extends TestCase
{
    private function fails(string $type, UploadedFile $file): array
    {
        $v = Validator::make(['image' => $file], ['image' => [new ImageGuideline($type)]]);
        return $v->errors()->get('image');
    }

    public function test_a_700kb_banner_now_passes(): void
    {
        // 1200x480 = 2.5:1 (valid ratio), 700 KB — over the old 400 KB cap, under the new 1 MB.
        $file = UploadedFile::fake()->image('ad.png', 1200, 480)->size(700);

        $this->assertEmpty($this->fails('ad_banner', $file), 'A 700 KB correctly-shaped banner must upload.');
    }

    public function test_an_800kb_gallery_image_now_passes(): void
    {
        $file = UploadedFile::fake()->image('g.png', 1080, 1080)->size(800);

        $this->assertEmpty($this->fails('gallery', $file));
    }

    public function test_over_one_mb_is_still_rejected(): void
    {
        $file = UploadedFile::fake()->image('ad.png', 1200, 480)->size(1200); // 1.2 MB

        $errors = $this->fails('ad_banner', $file);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('1024 KB', $errors[0]);
    }

    public function test_size_bump_does_not_relax_aspect_ratio(): void
    {
        // Right size (500 KB) and wide enough (>=1080), wrong shape (1:1 into a 2.5:1 slot).
        $file = UploadedFile::fake()->image('sq.png', 1080, 1080)->size(500);

        $errors = $this->fails('ad_banner', $file);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('aspect ratio', $errors[0]);
    }
}
