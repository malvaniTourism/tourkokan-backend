<?php

namespace App\Services;

use App\Models\ProductCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Compiles a ProductCategory's `attribute_schema` into Laravel validation rules.
 *
 * This is what lets a new product vertical ship as a single database row: the app renders
 * its Add-Product form from the schema, and the server validates against the same schema —
 * so the two can never drift, and neither needs a release.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §6.
 */
class ProductAttributeValidator
{
    /**
     * Attribute keys a schema may never declare.
     *
     * R5 — anything that varies by date (price, stock, availability) belongs in the future
     * `product_availability` table, never in the static `attributes` JSON. Enforcing this
     * mechanically is what keeps the booking calendar an additive change rather than a
     * data-archaeology project. See docs/VENDOR_PRODUCTS_DESIGN.md §3.
     */
    public const RESERVED_KEYS = [
        'price', 'sale_price', 'base_price', 'stock', 'quantity_available',
        'availability', 'available_dates', 'slots', 'booked', 'currency',
    ];

    /**
     * Build validation rules for a product's `attributes` payload.
     *
     * @param  array  $schema  the category's attribute_schema
     * @param  string $prefix  request key the attributes arrive under
     * @return array<string, array>
     */
    public function rules(array $schema, string $prefix = 'attributes'): array
    {
        $rules = [];

        foreach ($schema as $key => $spec) {
            $field = "{$prefix}.{$key}";
            $type  = $spec['type'] ?? 'string';

            $rule = [($spec['required'] ?? false) ? 'required' : 'nullable'];

            switch ($type) {
                case 'string':
                    $rule[] = 'string';
                    $rule[] = 'max:' . ($spec['max'] ?? 255);
                    break;

                case 'text':
                    $rule[] = 'string';
                    $rule[] = 'max:' . ($spec['max'] ?? 5000);
                    break;

                case 'int':
                    $rule[] = 'integer';
                    if (isset($spec['min'])) $rule[] = 'min:' . $spec['min'];
                    if (isset($spec['max'])) $rule[] = 'max:' . $spec['max'];
                    break;

                case 'decimal':
                    $rule[] = 'numeric';
                    if (isset($spec['min'])) $rule[] = 'min:' . $spec['min'];
                    if (isset($spec['max'])) $rule[] = 'max:' . $spec['max'];
                    break;

                case 'bool':
                    $rule[] = 'boolean';
                    break;

                case 'enum':
                    $rule[] = Rule::in($spec['options'] ?? []);
                    break;

                case 'multi':
                    $rule[] = 'array';
                    $rules["{$field}.*"] = ['string', Rule::in($spec['options'] ?? [])];
                    break;

                case 'date':
                    $rule[] = 'date';
                    break;

                case 'time':
                    $rule[] = 'date_format:H:i';
                    break;
            }

            $rules[$field] = $rule;
        }

        return $rules;
    }

    /**
     * Validate and cast a product's attributes against its category schema.
     *
     * Unknown keys are rejected rather than silently dropped — a typo in the app should
     * surface as an error, not as a quietly missing field.
     *
     * @return array{0: array, 1: array}  [castAttributes, errors]
     */
    public function validate(ProductCategory $category, array $attributes): array
    {
        $schema = $category->attribute_schema ?? [];

        if (empty($schema)) {
            return [[], []];
        }

        $unknown = array_diff(array_keys($attributes), array_keys($schema));
        if (!empty($unknown)) {
            return [[], [
                'attributes' => [
                    'Unknown attribute(s) for category "' . $category->name . '": '
                    . implode(', ', $unknown),
                ],
            ]];
        }

        $attributes = $this->normalize($schema, $attributes);

        $validator = Validator::make(
            ['attributes' => $attributes],
            $this->rules($schema),
            [],
            $this->attributeLabels($schema)
        );

        if ($validator->fails()) {
            return [[], $validator->errors()->toArray()];
        }

        return [$this->cast($schema, $attributes), []];
    }

    /**
     * Validate a schema definition itself — called when an admin creates or edits a category.
     *
     * @return array  list of human-readable errors; empty means valid
     */
    public function validateSchema(array $schema): array
    {
        $errors = [];

        foreach ($schema as $key => $spec) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                $errors[] = "Attribute key '{$key}' must be snake_case starting with a letter.";
                continue;
            }

            if (in_array($key, self::RESERVED_KEYS, true)) {
                $errors[] = "Attribute key '{$key}' is reserved — anything that varies by date "
                          . "belongs in pricing/availability, not in attributes (see design doc §3 R5).";
                continue;
            }

            if (!is_array($spec)) {
                $errors[] = "Attribute '{$key}' must be an object.";
                continue;
            }

            $type = $spec['type'] ?? null;
            if (!in_array($type, ProductCategory::ATTRIBUTE_TYPES, true)) {
                $errors[] = "Attribute '{$key}' has unsupported type '"
                          . (is_scalar($type) ? $type : gettype($type)) . "'. Allowed: "
                          . implode(', ', ProductCategory::ATTRIBUTE_TYPES) . '.';
                continue;
            }

            if (empty($spec['label'])) {
                $errors[] = "Attribute '{$key}' is missing a 'label' — the app renders it as the field name.";
            }

            if (in_array($type, ['enum', 'multi'], true)) {
                if (empty($spec['options']) || !is_array($spec['options'])) {
                    $errors[] = "Attribute '{$key}' is type '{$type}' and must declare a non-empty 'options' array.";
                }
            }
        }

        return $errors;
    }

    /**
     * Coerce multipart form values into the shapes Laravel's rules expect.
     *
     * The vendor flow is a React Native multipart upload, so every value arrives as a
     * string — `ac: "true"` and `cuisine: "[\"Malvani\"]"`. Laravel's `boolean` rule accepts
     * 1/0/"1"/"0" but not "true"/"false", and `array` obviously rejects a JSON string, so
     * without this step every bool and multi field in the app would fail validation.
     */
    private function normalize(array $schema, array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            $type = $schema[$key]['type'] ?? null;

            if ($type === 'bool' && is_string($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                // leave unparseable strings alone so validation reports them properly
                if ($parsed !== null) {
                    $attributes[$key] = $parsed;
                }
            }

            if ($type === 'multi' && is_string($value)) {
                $decoded = json_decode($value, true);
                $attributes[$key] = is_array($decoded)
                    ? $decoded
                    : array_filter(array_map('trim', explode(',', $value)), 'strlen');
            }
        }

        return $attributes;
    }

    /**
     * Cast validated values to their declared types.
     */
    private function cast(array $schema, array $attributes): array
    {
        $out = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                $out[$key] = null;
                continue;
            }

            $out[$key] = match ($schema[$key]['type'] ?? 'string') {
                'int'     => (int) $value,
                'decimal' => (float) $value,
                'bool'    => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'multi'   => array_values((array) $value),
                default   => $value,
            };
        }

        return $out;
    }

    /**
     * Use the schema's human labels in error messages instead of "attributes.occupancy".
     */
    private function attributeLabels(array $schema): array
    {
        $labels = [];

        foreach ($schema as $key => $spec) {
            $label = $spec['label'] ?? $key;

            $labels["attributes.{$key}"] = $label;
            // `multi` errors surface per element as attributes.cuisine.1 — give those a
            // human label too, since the message goes straight to the app's form UI.
            $labels["attributes.{$key}.*"] = $label;
        }

        return $labels;
    }
}
