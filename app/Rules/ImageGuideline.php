<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates an uploaded image against docs/IMAGE_GUIDELINES.md:
 * min width + aspect ratio (±10% tolerance) + max file size (KB).
 * Rules are config-driven — see config('constants.image_rules').
 *
 * Usage: 'image' => ['required', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('hero_site')]
 */
class ImageGuideline implements ValidationRule
{
    public function __construct(private string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Non-files are rejected by the accompanying mimes/image rules
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return;
        }

        $rules = config("constants.image_rules.{$this->type}");

        if (!$rules) {
            return;
        }

        if ($value->getSize() > $rules['max_kb'] * 1024) {
            $fail("The {$attribute} must not be larger than {$rules['max_kb']} KB.");
            return;
        }

        $dimensions = @getimagesize($value->getRealPath());

        if (!$dimensions) {
            $fail("The {$attribute} is not a valid image.");
            return;
        }

        [$width, $height] = $dimensions;

        if ($width < $rules['min_width']) {
            $fail("The {$attribute} width must be at least {$rules['min_width']}px (got {$width}px).");
            return;
        }

        $tolerance = config('constants.image_rules.ratio_tolerance', 0.10);
        $expected  = $rules['ratio'];
        $actual    = $height > 0 ? $width / $height : 0;

        if (abs($actual - $expected) > $expected * $tolerance) {
            $fail(sprintf(
                'The %s aspect ratio must be %.2f:1 (±%d%%), got %.2f:1.',
                $attribute,
                $expected,
                $tolerance * 100,
                $actual
            ));
        }
    }
}
