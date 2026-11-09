<?php

namespace Tests\Unit\Ai\Schema;

use App\Ai\Exceptions\UnsupportedSchemaProperty;
use App\Ai\Schema\Describe;
use App\Ai\Schema\SchemaBuilder;
use App\Ai\Schemas\InvoiceData;
use App\Ai\Schemas\TicketData;
use PHPUnit\Framework\TestCase;

class SchemaBuilderTest extends TestCase
{
    public function test_for_lists_every_invoice_property_in_required_and_forbids_additional_properties(): void
    {
        $schema = SchemaBuilder::for(InvoiceData::class);

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(array_keys($schema['properties']), $schema['required']);
        $this->assertSame(
            ['vendor_name', 'invoice_number', 'invoice_date', 'due_date', 'currency', 'line_items', 'subtotal', 'tax', 'total', 'error'],
            $schema['required'],
        );
    }

    public function test_for_leaves_the_extraction_meta_out_of_the_schema(): void
    {
        $schema = SchemaBuilder::for(InvoiceData::class);

        $this->assertArrayNotHasKey('meta', $schema['properties']);
    }

    public function test_for_types_nullable_properties_as_a_union_with_null_and_carries_the_description(): void
    {
        $schema = SchemaBuilder::for(InvoiceData::class);

        $this->assertSame(
            ['type' => ['string', 'null'], 'description' => 'Vendor or supplier name as printed'],
            $schema['properties']['vendor_name'],
        );
        $this->assertSame(['number', 'null'], $schema['properties']['total']['type']);
    }

    public function test_for_describes_array_items_with_the_line_item_object_schema(): void
    {
        $items = SchemaBuilder::for(InvoiceData::class)['properties']['line_items']['items'];

        $this->assertSame('object', $items['type']);
        $this->assertFalse($items['additionalProperties']);
        $this->assertSame(['description', 'quantity', 'unit_price', 'amount'], $items['required']);
        $this->assertSame('number', $items['properties']['amount']['type']);
    }

    public function test_for_emits_a_backed_enum_as_a_string_enum(): void
    {
        $category = SchemaBuilder::for(TicketData::class)['properties']['category'];

        $this->assertSame('string', $category['type']);
        $this->assertSame(['howto', 'billing', 'bug', 'account', 'other'], $category['enum']);
    }

    public function test_for_names_the_schema_after_the_class_in_snake_case(): void
    {
        $this->assertSame('invoice_data', SchemaBuilder::name(InvoiceData::class));
        $this->assertSame('ticket_data', SchemaBuilder::name(TicketData::class));
    }

    public function test_for_rejects_a_property_without_a_type(): void
    {
        $this->expectException(UnsupportedSchemaProperty::class);

        SchemaBuilder::for(UntypedDto::class);
    }

    public function test_for_rejects_an_array_without_an_items_attribute(): void
    {
        $this->expectException(UnsupportedSchemaProperty::class);

        SchemaBuilder::for(ArrayWithoutItemsDto::class);
    }
}

final class UntypedDto
{
    public function __construct(
        #[Describe('Anything')]
        public $anything,
    ) {}
}

final readonly class ArrayWithoutItemsDto
{
    public function __construct(
        #[Describe('Strings')]
        public array $strings,
    ) {}
}
