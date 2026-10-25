<?php

namespace App\Enums;

enum StaffUndoKind: string
{
    case BookingCancel = 'booking_cancel';
    case AssetDelete = 'asset_delete';
    case PoolDelete = 'pool_delete';
    case ClosureDelete = 'closure_delete';
    case PromoDelete = 'promo_delete';
    case WaitlistRemove = 'waitlist_remove';
}
