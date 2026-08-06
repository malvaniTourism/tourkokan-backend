<?php

namespace Tests\Unit;

use App\Models\ProductCategory;
use App\Services\ProductAttributeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The attribute-schema engine is what lets a new product vertical ship as one database
 * row, so its edges matter more than most: a hole here becomes bad data in every vertical.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §6.
 */
class ProductAttributeValidatorTest extends TestCase
{
    private ProductAttributeValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ProductAttributeValidator();
    }

    /**
     * Build an unsaved category — these tests never touch the database.
     */
    private function category(array $schema, string $name = 'Room Night'): ProductCategory
    {
        $category = new ProductCategory();
        $category->name = $name;
        $category->attribute_schema = $schema;

        return $category;
    }

    private function roomSchema(): array
    {
        return [
            'occupancy' => ['type' => 'int', 'label' => 'Max guests', 'required' => true, 'min' => 1, 'max' => 20],
            'ac'        => ['type' => 'bool', 'label' => 'Air conditioned'],
            'meal_plan' => ['type' => 'enum', 'label' => 'Meal plan', 'options' => ['EP', 'CP', 'MAP', 'AP']],
            'cuisine'   => ['type' => 'multi', 'label' => 'Cuisine', 'options' => ['Malvani', 'Konkani']],
            'check_in'  => ['type' => 'time', 'label' => 'Check-in'],
            'notes'     => ['type' => 'text', 'label' => 'Notes'],
        ];
    }

    public function test_accepts_a_valid_payload_and_casts_to_declared_types(): void
    {
        [$cast, $errors] = $this->validator->validate(
            $this->category($this->roomSchema()),
            ['occupancy' => '4', 'ac' => '1', 'meal_plan' => 'CP', 'check_in' => '12:00']
        );

        $this->assertSame([], $errors);
        $this->assertSame(4, $cast['occupancy'], 'int attributes must be cast to int');
        $this->assertTrue($cast['ac'], 'bool attributes must be cast to bool');
        $this->assertSame('CP', $cast['meal_plan']);
    }

    public function test_a_category_with_no_schema_accepts_nothing_and_errors_on_nothing(): void
    {
        [$cast, $errors] = $this->validator->validate($this->category([]), ['anything' => 'goes']);

        $this->assertSame([], $errors);
        $this->assertSame([], $cast);
    }

    public function test_required_attributes_are_enforced(): void
    {
        [, $errors] = $this->validator->validate($this->category($this->roomSchema()), ['ac' => true]);

        $this->assertArrayHasKey('attributes.occupancy', $errors);
    }

    public function test_int_bounds_and_enum_options_are_enforced(): void
    {
        [, $errors] = $this->validator->validate(
            $this->category($this->roomSchema()),
            ['occupancy' => 99, 'meal_plan' => 'PIZZA']
        );

        $this->assertArrayHasKey('attributes.occupancy', $errors);
        $this->assertArrayHasKey('attributes.meal_plan', $errors);
    }

    public function test_unknown_attribute_keys_are_rejected_rather_than_silently_dropped(): void
    {
        [$cast, $errors] = $this->validator->validate(
            $this->category($this->roomSchema()),
            ['occupancy' => 2, 'jacuzzi' => true]
        );

        $this->assertArrayHasKey('attributes', $errors);
        $this->assertStringContainsString('jacuzzi', $errors['attributes'][0]);
        $this->assertSame([], $cast, 'nothing should be persisted when an unknown key is present');
    }

    public function test_error_messages_use_the_human_label_from_the_schema(): void
    {
        [, $errors] = $this->validator->validate($this->category($this->roomSchema()), []);

        $this->assertStringContainsString(
            'Max guests',
            $errors['attributes.occupancy'][0],
            'the app renders these messages in its form UI'
        );
    }

    // ── Multipart normalisation ──────────────────────────────────────────────────
    // Vendors add products from the React Native app over multipart/form-data, where
    // every value arrives as a string. Laravel's `boolean` rule accepts 1/0/"1"/"0" but
    // not "true"/"false", and `array` rejects a JSON string, so without normalisation
    // every bool and multi field in the app would fail. See design doc §8.

    #[DataProvider('truthyAndFalsyStrings')]
    public function test_bool_attributes_accept_the_string_forms_the_app_sends($input, bool $expected): void
    {
        [$cast, $errors] = $this->validator->validate(
            $this->category($this->roomSchema()),
            ['occupancy' => 2, 'ac' => $input]
        );

        $this->assertSame([], $errors, "bool should accept " . var_export($input, true));
        $this->assertSame($expected, $cast['ac']);
    }

    public static function truthyAndFalsyStrings(): array
    {
        return [
            'string true'  => ['true', true],
            'string false' => ['false', false],
            'string one'   => ['1', true],
            'string zero'  => ['0', false],
            'yes'          => ['yes', true],
            'no'           => ['no', false],
            'real bool'    => [true, true],
        ];
    }

    public function test_unparseable_bool_still_fails_rather_than_defaulting(): void
    {
        [, $errors] = $this->validator->validate(
            $this->category($this->roomSchema()),
            ['occupancy' => 2, 'ac' => 'maybe']
        );

        $this->assertArrayHasKey('attributes.ac', $errors);
    }

    #[DataProvider('multiInputShapes')]
    public function test_multi_attributes_accept_every_shape_the_app_can_send($input, array $expected): void
    {
        [$cast, $errors] = $this->validator->validate(
            $this->category($this->roomSchema()),
            ['occupancy' => 2, 'cuisine' => $input]
        );

        $this->assertSame([], $errors);
        $this->assertSame($expected, $cast['cuisine']);
    }

    public static function multiInputShapes(): array
    {
        return [
            'json string' => ['["Malvani","Konkani"]', ['Malvani', 'Konkani']],
            'csv string'  => ['Malvani, Konkani', ['Malvani', 'Konkani']],
            'php array'   => [['Malvani', 'Konkani'], ['Malvani', 'Konkani']],
            'single'      => ['Malvani', ['Malvani']],
        ];
    }

    public function test_multi_rejects_a_member_outside_the_declared_options(): void
    {
        [, $errors] = $this->validator->validate(
            $this->category($this->roomSchema()),
            ['occupancy' => 2, 'cuisine' => '["Malvani","Sushi"]']
        );

        $this->assertArrayHasKey('attributes.cuisine.1', $errors);
        $this->assertStringContainsString('Cuisine', $errors['attributes.cuisine.1'][0]);
    }

    // ── Schema definition validation (admin-facing) ──────────────────────────────

    public function test_a_well_formed_schema_passes(): void
    {
        $this->assertSame([], $this->validator->validateSchema($this->roomSchema()));
    }

    /**
     * R5 — anything varying by date belongs in pricing/availability, never in the static
     * attributes JSON. Enforced mechanically so the booking calendar stays an additive
     * change. See design doc §3.
     *
     */
    #[DataProvider('reservedKeys')]
    public function test_reserved_keys_are_refused_in_a_schema(string $key): void
    {
        $errors = $this->validator->validateSchema([
            $key => ['type' => 'decimal', 'label' => 'X'],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('reserved', $errors[0]);
    }

    public static function reservedKeys(): array
    {
        return array_map(fn($k) => [$k], ProductAttributeValidator::RESERVED_KEYS);
    }

    public function test_schema_keys_must_be_snake_case(): void
    {
        $errors = $this->validator->validateSchema(['Bad Key' => ['type' => 'int', 'label' => 'x']]);

        $this->assertStringContainsString('snake_case', $errors[0]);
    }

    public function test_schema_rejects_an_unsupported_type(): void
    {
        $errors = $this->validator->validateSchema(['x' => ['type' => 'unicorn', 'label' => 'x']]);

        $this->assertStringContainsString('unsupported type', $errors[0]);
    }

    public function test_schema_requires_a_label_because_the_app_renders_it(): void
    {
        $errors = $this->validator->validateSchema(['occupancy' => ['type' => 'int']]);

        $this->assertStringContainsString('label', $errors[0]);
    }

    public function test_enum_and_multi_must_declare_options(): void
    {
        foreach (['enum', 'multi'] as $type) {
            $errors = $this->validator->validateSchema(['x' => ['type' => $type, 'label' => 'X']]);

            $this->assertNotEmpty($errors, "{$type} without options must be rejected");
            $this->assertStringContainsString('options', $errors[0]);
        }
    }
}
