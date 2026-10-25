<?php

namespace App\Services\Inventory;

use App\Enums\ResourceType;
use App\Models\Asset;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Models\Tenant;
use App\Tenancy\StaffLocationAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves a closure for one location. A category and a single resource are
 * alternatives. The row does not change any booking.
 */
final class ClosureWriter
{
    public function __construct(private readonly ClosureCalendar $calendar) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(Tenant $tenant, array $input, ?LocationClosure $existing = null): LocationClosure
    {
        $validated = $this->validate($tenant, $input);
        $attributes = [
            'tenant_id' => $tenant->id,
            'location_id' => $validated['location_id'],
            'resource_category_id' => $validated['resource_category_id'],
            'resource_type' => $validated['resource_type'],
            'resource_id' => $validated['resource_id'],
            'reason' => $validated['reason'],
            'all_day' => $validated['all_day'],
            'recurs_yearly' => $validated['recurs_yearly'],
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'],
            'starts_local' => $validated['starts_local'],
            'ends_local' => $validated['ends_local'],
        ];

        if ($existing instanceof LocationClosure) {
            $existing->fill($attributes)->save();

            return $existing->refresh();
        }

        return LocationClosure::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     location_id: string,
     *     resource_category_id: string|null,
     *     resource_type: string|null,
     *     resource_id: string|null,
     *     reason: string,
     *     all_day: bool,
     *     recurs_yearly: bool,
     *     starts_on: string,
     *     ends_on: string,
     *     starts_local: string|null,
     *     ends_local: string|null
     * }
     */
    private function validate(Tenant $tenant, array $input): array
    {
        $validator = Validator::make($input, [
            'location_id' => ['required', 'string', app(StaffLocationAccess::class)->locationExistsRule($tenant->id)],
            'scope' => ['required', Rule::in(['location', 'category', 'resource'])],
            'resource_category_id' => ['nullable', 'string'],
            'resource_key' => ['nullable', 'string'],
            'reason' => ['required', 'string', 'max:200'],
            'span' => ['required', Rule::in(['day', 'hours'])],
            'yearly' => ['required', 'boolean'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'starts_local' => ['nullable', 'date_format:H:i'],
            'ends_local' => ['nullable', 'date_format:H:i'],
        ]);

        $validator->after(function ($validator) use ($tenant): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var array<string, mixed> $data */
            $data = $validator->getData();
            $startsOn = (string) $data['starts_on'];
            $endsOn = (string) $data['ends_on'];

            $invalidDate = false;

            if (! $this->realDate($startsOn)) {
                $validator->errors()->add('starts_on', 'Choose a real start date.');
                $invalidDate = true;
            }

            if (! $this->realDate($endsOn)) {
                $validator->errors()->add('ends_on', 'Choose a real end date.');
                $invalidDate = true;
            }

            if ($invalidDate) {
                return;
            }

            $invalid = false;

            if ($endsOn < $startsOn) {
                $validator->errors()->add('ends_on', 'Choose an end date on or after the start.');
                $invalid = true;
            }

            if (trim((string) $data['reason']) === '') {
                $validator->errors()->add('reason', 'Add a reason.');
                $invalid = true;
            }

            $allDay = $data['span'] === 'day';
            $startsLocal = is_string($data['starts_local'] ?? null) ? $data['starts_local'] : '';
            $endsLocal = is_string($data['ends_local'] ?? null) ? $data['ends_local'] : '';

            if (! $allDay && ($startsLocal === '' || $endsLocal === '')) {
                $validator->errors()->add('ends_local', 'Choose the hours that are closed.');
                $invalid = true;
            }

            if (! $allDay && $startsOn === $endsOn && $endsLocal !== '' && $startsLocal !== '' && $endsLocal <= $startsLocal) {
                $validator->errors()->add('ends_local', 'Choose an end after the start.');
                $invalid = true;
            }

            if ($data['scope'] === 'category') {
                $categoryId = is_string($data['resource_category_id'] ?? null) ? $data['resource_category_id'] : '';
                $category = $categoryId !== '' ? ResourceCategory::query()->find($categoryId) : null;

                if (! $category instanceof ResourceCategory || $category->tenant_id !== $tenant->id) {
                    $validator->errors()->add('resource_category_id', 'Choose a category in this workspace.');
                    $invalid = true;
                }
            }

            if ($data['scope'] === 'resource' && $this->resource($tenant, (string) $data['location_id'], (string) ($data['resource_key'] ?? '')) === null) {
                $validator->errors()->add('resource_key', $tenant->industry->closurePickError());
                $invalid = true;
            }

            if ($invalid) {
                return;
            }

            $location = Location::query()->find($data['location_id']);

            if (! $location instanceof Location) {
                $validator->errors()->add('location_id', 'Choose a location in this workspace.');

                return;
            }

            $window = $this->calendar->concrete(
                $location->timezone,
                $allDay,
                $startsOn,
                $endsOn,
                $startsLocal !== '' ? $startsLocal : null,
                $endsLocal !== '' ? $endsLocal : null,
            );

            if ($window[1]->lessThanOrEqualTo($window[0])) {
                $validator->errors()->add('ends_local', 'That time does not exist on this date.');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();
        $allDay = $data['span'] === 'day';
        $scope = (string) $data['scope'];
        $categoryId = null;
        $resourceType = null;
        $resourceId = null;

        if ($scope === 'category') {
            $categoryId = (string) $data['resource_category_id'];
        }

        if ($scope === 'resource') {
            $parsed = $this->resource($tenant, (string) $data['location_id'], (string) ($data['resource_key'] ?? ''));
            $resourceType = $parsed[0] ?? null;
            $resourceId = $parsed[1] ?? null;
        }

        $startsLocal = is_string($data['starts_local'] ?? null) ? $data['starts_local'] : '';
        $endsLocal = is_string($data['ends_local'] ?? null) ? $data['ends_local'] : '';

        return [
            'location_id' => (string) $data['location_id'],
            'resource_category_id' => $categoryId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'reason' => trim((string) $data['reason']),
            'all_day' => $allDay,
            'recurs_yearly' => (bool) $data['yearly'],
            'starts_on' => (string) $data['starts_on'],
            'ends_on' => (string) $data['ends_on'],
            'starts_local' => $allDay ? null : $startsLocal.':00',
            'ends_local' => $allDay ? null : $endsLocal.':00',
        ];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resource(Tenant $tenant, string $locationId, string $key): ?array
    {
        if (preg_match('/^(asset|pool):([0-9A-HJKMNP-TV-Z]{26})$/i', $key, $match) !== 1) {
            return null;
        }

        if ($match[1] === ResourceType::Asset->value) {
            $asset = Asset::query()->find($match[2]);

            if ($asset instanceof Asset && $asset->tenant_id === $tenant->id && $asset->location_id === $locationId) {
                return [ResourceType::Asset->value, $asset->id];
            }

            return null;
        }

        $pool = Pool::query()->find($match[2]);

        if ($pool instanceof Pool && $pool->tenant_id === $tenant->id && $pool->location_id === $locationId) {
            return [ResourceType::Pool->value, $pool->id];
        }

        return null;
    }

    private function realDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
