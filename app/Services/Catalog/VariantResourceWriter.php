<?php

namespace App\Services\Catalog;

use App\Enums\ResourceType;
use App\Models\Asset;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\ServiceVariantResource;
use Illuminate\Validation\ValidationException;

final class VariantResourceWriter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function replace(ServiceVariant $variant, Service $service, array $rows): void
    {
        $prepared = [];

        foreach ($rows as $index => $row) {
            $prepared[] = $this->one($service, $row, $index);
        }

        $variant->resources()->delete();

        foreach ($prepared as $row) {
            ServiceVariantResource::query()->create([
                'tenant_id' => $variant->tenant_id,
                'service_variant_id' => $variant->id,
                'resource_type' => $row['resource_type'],
                'resource_category_id' => $row['resource_category_id'],
                'resource_id' => $row['resource_id'],
                'quantity' => $row['quantity'],
                'capacity' => $row['capacity'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{resource_type: ResourceType, resource_category_id: string, resource_id: string, quantity: int, capacity: int|null}
     */
    private function one(Service $service, array $row, int $index): array
    {
        $typeValue = $row['resource_type'] ?? null;
        $type = is_string($typeValue) ? ResourceType::tryFrom($typeValue) : null;

        if (! $type instanceof ResourceType) {
            throw ValidationException::withMessages([
                "resources.$index.resource_type" => 'Choose an asset or a pool.',
            ]);
        }

        $categoryId = is_string($row['resource_category_id'] ?? null) ? $row['resource_category_id'] : '';
        $category = ResourceCategory::query()->find($categoryId);

        if (! $category instanceof ResourceCategory) {
            throw ValidationException::withMessages([
                "resources.$index.resource_category_id" => 'That asset type is not in this workspace.',
            ]);
        }

        $resourceId = is_string($row['resource_id'] ?? null) ? $row['resource_id'] : '';
        $quantity = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
        $capacity = isset($row['capacity']) && $row['capacity'] !== '' && is_numeric($row['capacity'])
            ? (int) $row['capacity']
            : null;

        if ($type === ResourceType::Asset) {
            $asset = Asset::query()->find($resourceId);

            if (! $asset instanceof Asset || $asset->location_id !== $service->location_id) {
                throw ValidationException::withMessages([
                    "resources.$index.resource_id" => 'That resource is not at this service location.',
                ]);
            }

            if ($asset->resource_category_id !== $category->id) {
                throw ValidationException::withMessages([
                    "resources.$index.resource_category_id" => 'That resource is not this asset type.',
                ]);
            }

            if ($quantity !== 1) {
                throw ValidationException::withMessages([
                    "resources.$index.quantity" => 'An individual asset can only be mapped once.',
                ]);
            }

            if ($capacity !== null && (int) $asset->capacity !== $capacity) {
                throw ValidationException::withMessages([
                    "resources.$index.capacity" => 'That asset does not have this capacity.',
                ]);
            }

            return [
                'resource_type' => $type,
                'resource_category_id' => $category->id,
                'resource_id' => $asset->id,
                'quantity' => $quantity,
                'capacity' => $capacity,
            ];
        }

        $pool = Pool::query()->find($resourceId);

        if (! $pool instanceof Pool || $pool->location_id !== $service->location_id) {
            throw ValidationException::withMessages([
                "resources.$index.resource_id" => 'That resource is not at this service location.',
            ]);
        }

        if ($pool->resource_category_id !== $category->id) {
            throw ValidationException::withMessages([
                "resources.$index.resource_category_id" => 'That resource is not this asset type.',
            ]);
        }

        return [
            'resource_type' => $type,
            'resource_category_id' => $category->id,
            'resource_id' => $pool->id,
            'quantity' => $quantity,
            'capacity' => $capacity,
        ];
    }
}
