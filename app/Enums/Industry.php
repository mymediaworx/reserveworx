<?php

namespace App\Enums;

/**
 * What a workspace rents or runs. Boat rentals and marinas keep the original
 * staff sentences. Every other industry uses the same screens with its own nouns.
 */
enum Industry: string
{
    case BoatsMarinas = 'boats_marinas';
    case BoatRentals = 'boat_rentals';
    case MarinaManagement = 'marina_management';
    case ConstructionEquipment = 'construction_equipment';
    case EquipmentTools = 'equipment_tools';
    case GolfCartRentals = 'golf_cart_rentals';
    case AtvOffRoad = 'atv_off_road';
    case TourOperations = 'tour_operations';
    case WineTours = 'wine_tours';
    case SpasWellness = 'spas_wellness';
    case JetSkiPwc = 'jet_ski_pwc';
    case PontoonBoats = 'pontoon_boats';
    case YachtCharter = 'yacht_charter';
    case EBikes = 'e_bikes';
    case RvCamper = 'rv_camper';
    case MopedScooter = 'moped_scooter';
    case SkiSnowboard = 'ski_snowboard';
    case CameraGear = 'camera_gear';

    public static function fromOptional(mixed $value): self
    {
        if (! is_string($value) || $value === '') {
            return self::BoatRentals;
        }

        return self::from($value);
    }

    public function label(): string
    {
        return match ($this) {
            self::BoatsMarinas => 'Boats & Marinas',
            self::BoatRentals => 'Boat Rentals',
            self::MarinaManagement => 'Marina Management',
            self::ConstructionEquipment => 'Construction Equipment',
            self::EquipmentTools => 'Equipment & Tools',
            self::GolfCartRentals => 'Golf Cart Rentals',
            self::AtvOffRoad => 'ATV & Off-Road Rentals',
            self::TourOperations => 'Tour Operations',
            self::WineTours => 'Wine Tours',
            self::SpasWellness => 'Spas & Wellness',
            self::JetSkiPwc => 'Jet Ski & PWC',
            self::PontoonBoats => 'Pontoon Boats',
            self::YachtCharter => 'Yacht Charter',
            self::EBikes => 'E-Bikes',
            self::RvCamper => 'RV & Camper',
            self::MopedScooter => 'Moped & Scooter',
            self::SkiSnowboard => 'Ski & Snowboard',
            self::CameraGear => 'Camera & Gear',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::BoatsMarinas, self::BoatRentals, self::JetSkiPwc, self::PontoonBoats, self::YachtCharter => 'boat',
            self::MarinaManagement => 'anchor',
            self::ConstructionEquipment => 'hard-hat',
            self::EquipmentTools => 'wrench',
            self::GolfCartRentals => 'car',
            self::AtvOffRoad, self::RvCamper => 'truck',
            self::TourOperations => 'route',
            self::WineTours => 'wine',
            self::SpasWellness => 'sparkles',
            self::EBikes, self::MopedScooter => 'bike',
            self::SkiSnowboard => 'mountain',
            self::CameraGear => 'camera',
        };
    }

    public function catalog(): string
    {
        return match ($this) {
            self::BoatsMarinas, self::BoatRentals => 'Boats & gear',
            self::MarinaManagement => 'Slips',
            self::ConstructionEquipment, self::EquipmentTools => 'Equipment',
            self::GolfCartRentals => 'Golf carts',
            self::AtvOffRoad => 'ATVs',
            self::TourOperations, self::WineTours => 'Departures',
            self::SpasWellness => 'Rooms',
            self::JetSkiPwc => 'Watercraft',
            self::PontoonBoats => 'Pontoons',
            self::YachtCharter => 'Yachts',
            self::EBikes => 'Bikes',
            self::RvCamper => 'Rigs',
            self::MopedScooter => 'Scooters',
            self::SkiSnowboard => 'Gear sets',
            self::CameraGear => 'Cameras',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::BoatsMarinas, self::BoatRentals => 'boat',
            self::MarinaManagement => 'slip',
            self::ConstructionEquipment => 'machine',
            self::EquipmentTools => 'tool',
            self::GolfCartRentals => 'cart',
            self::AtvOffRoad => 'ATV',
            self::TourOperations, self::WineTours => 'tour',
            self::SpasWellness => 'room',
            self::JetSkiPwc => 'watercraft',
            self::PontoonBoats => 'pontoon',
            self::YachtCharter => 'yacht',
            self::EBikes => 'bike',
            self::RvCamper => 'rig',
            self::MopedScooter => 'scooter',
            self::SkiSnowboard => 'set',
            self::CameraGear => 'camera',
        };
    }

    public function units(): string
    {
        return match ($this) {
            self::BoatsMarinas, self::BoatRentals => 'boats',
            self::MarinaManagement => 'slips',
            self::ConstructionEquipment => 'machines',
            self::EquipmentTools => 'tools',
            self::GolfCartRentals => 'carts',
            self::AtvOffRoad => 'ATVs',
            self::TourOperations, self::WineTours => 'tours',
            self::SpasWellness => 'rooms',
            self::JetSkiPwc => 'watercraft',
            self::PontoonBoats => 'pontoons',
            self::YachtCharter => 'yachts',
            self::EBikes => 'bikes',
            self::RvCamper => 'rigs',
            self::MopedScooter => 'scooters',
            self::SkiSnowboard => 'sets',
            self::CameraGear => 'cameras',
        };
    }

    public function usesLegacyBoatCopy(): bool
    {
        return $this === self::BoatRentals || $this === self::BoatsMarinas;
    }

    public function article(): string
    {
        $first = strtolower($this->unit()[0]);

        return in_array($first, ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }

    public function resourcesLede(): string
    {
        if ($this->usesLegacyBoatCopy()) {
            return 'Everything that can be booked. Bookings never take the same boat twice, across rentals and tours.';
        }

        return 'Everything that can be booked. Bookings never take the same '.$this->unit().' twice, across rentals and tours.';
    }

    public function needsLocation(): string
    {
        if ($this->usesLegacyBoatCopy()) {
            return 'Add a location first. Gear always belongs to a location.';
        }

        return 'Add a location first. Every '.$this->unit().' belongs to a location.';
    }

    public function addHeading(): string
    {
        return $this->usesLegacyBoatCopy() ? 'Add gear' : 'Add '.$this->unit();
    }

    public function countedExample(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'Counted gear, like 12 single kayaks'
            : 'Counted gear, like 12 '.$this->units();
    }

    public function trackedExample(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'One tracked item, like a pontoon boat or a guide'
            : 'One tracked item, like '.$this->article().' '.$this->unit().' or a guide';
    }

    public function poolPlaceholder(): string
    {
        return $this->usesLegacyBoatCopy() ? 'Single kayak' : ucfirst($this->unit());
    }

    public function assetPlaceholder(): string
    {
        return $this->usesLegacyBoatCopy() ? 'Pontoon boat' : ucfirst($this->unit());
    }

    public function servicePlaceholder(): string
    {
        return $this->usesLegacyBoatCopy() ? 'Single kayak rental' : ucfirst($this->unit()).' rental';
    }

    public function scheduleHint(string $view): string
    {
        if ($view === 'day') {
            return $this->usesLegacyBoatCopy()
                ? 'Tap an empty hour on a boat or pool to start a booking.'
                : 'Tap an empty hour on '.$this->article().' '.$this->unit().' or pool to start a booking.';
        }

        return $this->usesLegacyBoatCopy()
            ? 'Tap a day on a boat or pool, then pick the start.'
            : 'Tap a day on '.$this->article().' '.$this->unit().' or pool, then pick the start.';
    }

    public function moveCheck(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'Moving still checks the boats.'
            : 'Moving still checks the '.$this->units().'.';
    }

    public function emptyLocation(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'No boats or gear at this location yet.'
            : 'No '.$this->units().' at this location yet.';
    }

    public function holdButton(): string
    {
        return $this->usesLegacyBoatCopy() ? 'Hold these boats' : 'Hold these '.$this->units();
    }

    public function holdNote(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'Holding reserves the boats while you take payment. Price and payment are on the next screen.'
            : 'Holding reserves the '.$this->units().' while you take payment. Price and payment are on the next screen.';
    }

    public function closureLede(): string
    {
        if ($this->usesLegacyBoatCopy()) {
            return 'Close a location, one category, or a single boat for a day or a few hours. A holiday can repeat every year. Confirmed bookings that overlap stay booked.';
        }

        return 'Close a location, one category, or a single '.$this->unit().' for a day or a few hours. A holiday can repeat every year. Confirmed bookings that overlap stay booked.';
    }

    public function closureOption(): string
    {
        return $this->usesLegacyBoatCopy() ? 'One boat or pool' : 'One '.$this->unit().' or pool';
    }

    public function closurePickError(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'Choose a boat or pool at this location.'
            : 'Choose '.$this->article().' '.$this->unit().' or pool at this location.';
    }

    public function locationClosureNote(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'These close the whole location for those dates. A few hours, one boat, or a holiday that repeats each year is on'
            : 'These close the whole location for those dates. A few hours, one '.$this->unit().', or a holiday that repeats each year is on';
    }

    public function holdExpiry(): string
    {
        return $this->usesLegacyBoatCopy() ? 'the boats are released' : 'the '.$this->units().' are released';
    }

    public function noShowConfirm(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'Mark this booking as a no-show? The boats are released.'
            : 'Mark this booking as a no-show? The '.$this->units().' are released.';
    }

    public function cancelConfirm(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'Cancel this booking? The boats are released. Refunds are handled separately.'
            : 'Cancel this booking? The '.$this->units().' are released. Refunds are handled separately.';
    }

    public function dashboardSetup(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'Add a location, the boats and gear you rent, and one service with a price. It takes about five minutes.'
            : 'Add a location, the '.$this->units().' you rent, and one service with a price. It takes about five minutes.';
    }

    public function holdMinutesLabel(): string
    {
        return $this->usesLegacyBoatCopy()
            ? 'Hold boats during checkout for (minutes)'
            : 'Hold '.$this->units().' during checkout for (minutes)';
    }
}
