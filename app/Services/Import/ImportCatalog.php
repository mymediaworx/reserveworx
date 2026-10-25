<?php

namespace App\Services\Import;

use App\Enums\ImportType;

/**
 * Columns a CSV can fill, and the header row of each downloadable template.
 */
final class ImportCatalog
{
    /**
     * @return array<string, array{label: string, required: bool, aliases: list<string>}>
     */
    public function fields(ImportType $type): array
    {
        return match ($type) {
            ImportType::Customers => [
                'name' => $this->field('Name', true, ['name', 'full name', 'customer name', 'customer']),
                'email' => $this->field('Email', true, ['email', 'e-mail', 'e mail', 'email address']),
                'phone' => $this->field('Phone', false, ['phone', 'phone number', 'telephone', 'mobile', 'cell']),
            ],
            ImportType::Inventory => [
                'kind' => $this->field('Kind', true, ['kind', 'type', 'item type']),
                'name' => $this->field('Name', true, ['name', 'item', 'item name']),
                'location' => $this->field('Location', true, ['location', 'location name', 'site']),
                'category' => $this->field('Category', false, ['category', 'resource category', 'asset type']),
                'quantity' => $this->field('Quantity', false, ['quantity', 'qty', 'count']),
                'capacity' => $this->field('Capacity', false, ['capacity', 'seats', 'people']),
                'status' => $this->field('Status', false, ['status']),
                'is_guide' => $this->field('Guide', false, ['is_guide', 'is guide', 'guide']),
                'prep_buffer_minutes' => $this->field('Prep buffer minutes', false, ['prep_buffer_minutes', 'prep buffer', 'prep buffer minutes', 'prep minutes']),
                'return_buffer_minutes' => $this->field('Return buffer minutes', false, ['return_buffer_minutes', 'return buffer', 'return buffer minutes', 'return minutes']),
                'turnaround_minutes' => $this->field('Turnaround minutes', false, ['turnaround_minutes', 'turnaround', 'turnaround minutes']),
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function header(ImportType $type): array
    {
        return array_keys($this->fields($type));
    }

    /**
     * @return list<list<string>>
     */
    public function examples(ImportType $type): array
    {
        return match ($type) {
            ImportType::Customers => [
                ['Ada Lovelace', 'ada@example.com', '312-555-0100'],
            ],
            ImportType::Inventory => [
                ['pool', 'Single kayaks', 'North dock', 'Kayaks', '12', '1', 'active', 'no', '0', '0', '15'],
                ['asset', 'Pontoon 1', 'North dock', 'Boats', '1', '8', 'active', 'no', '10', '10', '20'],
            ],
        };
    }

    public function templateFilename(ImportType $type): string
    {
        return $type->value.'-template.csv';
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    public function suggest(ImportType $type, array $headers): array
    {
        $mapping = [];
        $used = [];

        foreach ($this->fields($type) as $key => $field) {
            $mapping[$key] = '';

            foreach ($headers as $index => $header) {
                if (isset($used[$index])) {
                    continue;
                }

                if (in_array($this->normalize($header), $field['aliases'], true)) {
                    $mapping[$key] = (string) $index;
                    $used[$index] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    public function normalize(string $header): string
    {
        $normalized = str_replace(['_', '-'], ' ', strtolower(trim($header)));
        $collapsed = preg_replace('/\s+/', ' ', $normalized);

        return is_string($collapsed) ? trim($collapsed) : trim($normalized);
    }

    /**
     * @param  list<string>  $aliases
     * @return array{label: string, required: bool, aliases: list<string>}
     */
    private function field(string $label, bool $required, array $aliases): array
    {
        return [
            'label' => $label,
            'required' => $required,
            'aliases' => array_map($this->normalize(...), $aliases),
        ];
    }
}
