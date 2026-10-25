<?php

namespace App\Services\Import;

use App\Enums\ResourceStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;

/**
 * An inventory row is one tracked asset or one pool. A match is the same name at the same location.
 */
final class InventoryRowWriter implements RowWriter
{
    private string $tenantId = '';

    /** @var array<string, Location> */
    private array $locations = [];

    /** @var array<string, ResourceCategory> */
    private array $categories = [];

    public function prepare(string $tenantId, User $actor): void
    {
        if (app(TenantManager::class)->id() !== $tenantId) {
            throw new ImportException('The import lost its workspace.');
        }

        $this->tenantId = $tenantId;
        $this->locations = [];
        $this->categories = [];
        $allowed = app(StaffLocationAccess::class)->assignedIds($actor, $tenantId);

        foreach (Location::query()->orderBy('id')->get() as $location) {
            if (is_array($allowed) && ! in_array($location->id, $allowed, true)) {
                continue;
            }

            $key = mb_strtolower(trim($location->name));

            if (! isset($this->locations[$key])) {
                $this->locations[$key] = $location;
            }
        }

        foreach (ResourceCategory::query()->orderBy('id')->get() as $category) {
            $key = mb_strtolower(trim($category->name));

            if (! isset($this->categories[$key])) {
                $this->categories[$key] = $category;
            }
        }
    }

    public function inspect(array $values): array
    {
        $parsed = $this->parse($values);

        return [
            'errors' => $parsed['errors'],
            'notice' => $parsed['notice'],
        ];
    }

    public function apply(array $values): array
    {
        $parsed = $this->parse($values);

        if ($parsed['errors'] !== []) {
            return [
                'outcome' => 'skipped',
                'reason' => implode(' ', $parsed['errors']),
            ];
        }

        $existing = $parsed['existing'];

        if ($existing instanceof Asset || $existing instanceof Pool) {
            $this->update($existing, $parsed);

            return ['outcome' => 'updated', 'reason' => ''];
        }

        $this->create($parsed);

        return ['outcome' => 'created', 'reason' => ''];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{
     *     errors: list<string>,
     *     notice: string,
     *     kind: string,
     *     name: string,
     *     location_id: string,
     *     category_id: string|null,
     *     quantity: int|null,
     *     capacity: int|null,
     *     status: ResourceStatus|null,
     *     is_guide: bool|null,
     *     prep: int|null,
     *     return_buffer: int|null,
     *     turnaround: int|null,
     *     existing: Asset|Pool|null
     * }
     */
    private function parse(array $values): array
    {
        $errors = [];
        $kind = $this->kind($values['kind'] ?? '');
        $name = trim($values['name'] ?? '');
        $locationName = trim($values['location'] ?? '');
        $categoryName = trim($values['category'] ?? '');
        $location = $locationName === '' ? null : ($this->locations[mb_strtolower($locationName)] ?? null);
        $category = null;

        if ($kind === null) {
            $errors[] = 'Kind must be asset or pool.';
        }

        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 120) {
            $errors[] = 'Name must be 120 characters or fewer.';
        }

        if ($locationName === '') {
            $errors[] = 'Location is required.';
        } elseif (! $location instanceof Location) {
            $errors[] = 'That location is not in this workspace.';
        }

        if ($categoryName !== '') {
            $category = $this->categories[mb_strtolower($categoryName)] ?? null;

            if (! $category instanceof ResourceCategory) {
                $errors[] = 'That category is not in this workspace.';
            }
        }

        $quantity = $this->optionalWhole($values, 'quantity', 0, 100_000, 'Quantity must be a whole number from 0 to 100,000.', $errors);
        $capacity = $this->optionalWhole($values, 'capacity', 1, 10_000, 'Capacity must be a whole number from 1 to 10,000.', $errors);
        $prep = $this->optionalWhole($values, 'prep_buffer_minutes', 0, 1440, 'Prep buffer must be a whole number of minutes from 0 to 1,440.', $errors);
        $returnBuffer = $this->optionalWhole($values, 'return_buffer_minutes', 0, 1440, 'Return buffer must be a whole number of minutes from 0 to 1,440.', $errors);
        $turnaround = $this->optionalWhole($values, 'turnaround_minutes', 0, 1440, 'Turnaround must be a whole number of minutes from 0 to 1,440.', $errors);
        $status = $this->status($values['status'] ?? null, $kind, $errors);
        $isGuide = $this->guide($values, $kind, $errors);
        $existing = null;
        $notice = '';

        if ($kind !== null && $name !== '' && $location instanceof Location) {
            $existing = $this->existing($kind, $location->id, $name);
        }

        $retired = $existing !== null && $existing->status === ResourceStatus::Retired;

        if ($retired) {
            $errors[] = 'That item is retired and was left in place.';
            $existing = null;
        } elseif ($kind === 'pool' && $existing === null && $quantity === null && $this->blank($values, 'quantity')) {
            $errors[] = 'Quantity is required for a pool.';
        } elseif ($existing !== null && $errors === []) {
            $notice = 'An item with this name is already at this location. The import will update it.';
        }

        return [
            'errors' => $errors,
            'notice' => $notice,
            'kind' => $kind ?? '',
            'name' => $name,
            'location_id' => $location instanceof Location ? $location->id : '',
            'category_id' => $category instanceof ResourceCategory ? $category->id : null,
            'quantity' => $quantity,
            'capacity' => $capacity,
            'status' => $status,
            'is_guide' => $isGuide,
            'prep' => $prep,
            'return_buffer' => $returnBuffer,
            'turnaround' => $turnaround,
            'existing' => $existing,
        ];
    }

    /**
     * @param  array{kind: string, name: string, location_id: string, category_id: string|null, quantity: int|null, capacity: int|null, status: ResourceStatus|null, is_guide: bool|null, prep: int|null, return_buffer: int|null, turnaround: int|null}  $parsed
     */
    private function create(array $parsed): void
    {
        $shared = [
            'tenant_id' => $this->tenantId,
            'location_id' => $parsed['location_id'],
            'resource_category_id' => $parsed['category_id'],
            'name' => $parsed['name'],
            'status' => $parsed['status'] ?? ResourceStatus::Active,
            'prep_buffer_minutes' => $parsed['prep'] ?? 0,
            'return_buffer_minutes' => $parsed['return_buffer'] ?? 0,
            'turnaround_minutes' => $parsed['turnaround'] ?? 0,
        ];

        if ($parsed['kind'] === 'pool') {
            Pool::query()->create($shared + [
                'quantity' => $parsed['quantity'] ?? 0,
            ]);

            return;
        }

        Asset::query()->create($shared + [
            'capacity' => $parsed['capacity'] ?? 1,
            'is_guide' => $parsed['is_guide'] ?? false,
        ]);
    }

    /**
     * @param  array{category_id: string|null, name: string, quantity: int|null, capacity: int|null, status: ResourceStatus|null, is_guide: bool|null, prep: int|null, return_buffer: int|null, turnaround: int|null}  $parsed
     */
    private function update(Asset|Pool $existing, array $parsed): void
    {
        $existing->name = $parsed['name'];

        if ($parsed['category_id'] !== null) {
            $existing->resource_category_id = $parsed['category_id'];
        }

        if ($parsed['status'] instanceof ResourceStatus) {
            $existing->status = $parsed['status'];
        }

        if ($parsed['prep'] !== null) {
            $existing->prep_buffer_minutes = $parsed['prep'];
        }

        if ($parsed['return_buffer'] !== null) {
            $existing->return_buffer_minutes = $parsed['return_buffer'];
        }

        if ($parsed['turnaround'] !== null) {
            $existing->turnaround_minutes = $parsed['turnaround'];
        }

        if ($existing instanceof Pool && $parsed['quantity'] !== null) {
            $existing->quantity = $parsed['quantity'];
        }

        if ($existing instanceof Asset) {
            if ($parsed['capacity'] !== null) {
                $existing->capacity = $parsed['capacity'];
            }

            if ($parsed['is_guide'] !== null) {
                $existing->is_guide = $parsed['is_guide'];
            }
        }

        if ($existing->isDirty()) {
            $existing->save();
        }
    }

    private function existing(string $kind, string $locationId, string $name): Asset|Pool|null
    {
        $nameKey = mb_strtolower($name);

        if ($kind === 'pool') {
            return $this->openPool($locationId, $nameKey);
        }

        return $this->openAsset($locationId, $nameKey);
    }

    private function openAsset(string $locationId, string $nameKey): ?Asset
    {
        $retired = null;
        $rows = Asset::query()
            ->where('location_id', $locationId)
            ->whereRaw('LOWER(name) = ?', [$nameKey])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($row->status === ResourceStatus::Retired) {
                $retired ??= $row;

                continue;
            }

            return $row;
        }

        return $retired;
    }

    private function openPool(string $locationId, string $nameKey): ?Pool
    {
        $retired = null;
        $rows = Pool::query()
            ->where('location_id', $locationId)
            ->whereRaw('LOWER(name) = ?', [$nameKey])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($row->status === ResourceStatus::Retired) {
                $retired ??= $row;

                continue;
            }

            return $row;
        }

        return $retired;
    }

    private function kind(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'asset', 'individual', 'tracked' => 'asset',
            'pool', 'pooled', 'quantity' => 'pool',
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     */
    private function optionalWhole(array $values, string $key, int $min, int $max, string $message, array &$errors): ?int
    {
        if ($this->blank($values, $key)) {
            return null;
        }

        $raw = trim($values[$key]);

        if (strlen($raw) > 7 || preg_match('/^\d+$/', $raw) !== 1) {
            $errors[] = $message;

            return null;
        }

        $number = (int) $raw;

        if ($number < $min || $number > $max) {
            $errors[] = $message;

            return null;
        }

        return $number;
    }

    /**
     * @param  list<string>  $errors
     */
    private function status(?string $value, ?string $kind, array &$errors): ?ResourceStatus
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $status = ResourceStatus::tryFrom(strtolower(trim($value)));

        if ($status === ResourceStatus::Retired) {
            $errors[] = $kind === 'pool'
                ? 'A pool cannot be retired.'
                : 'Retirement is a separate action.';

            return null;
        }

        if (! $status instanceof ResourceStatus || ! in_array($status->value, ResourceStatus::writable(), true)) {
            $errors[] = 'Status must be active, blocked, or maintenance.';

            return null;
        }

        return $status;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     */
    private function guide(array $values, ?string $kind, array &$errors): ?bool
    {
        if ($this->blank($values, 'is_guide') || $kind === 'pool') {
            return null;
        }

        $normalized = strtolower(trim($values['is_guide']));

        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        $errors[] = 'Guide must be yes or no.';

        return null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function blank(array $values, string $key): bool
    {
        return ! array_key_exists($key, $values) || trim($values[$key]) === '';
    }
}
