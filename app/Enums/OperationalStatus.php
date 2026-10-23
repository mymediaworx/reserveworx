<?php

namespace App\Enums;

enum OperationalStatus: string
{
    case NotArrived = 'not_arrived';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Returned = 'returned';
    case Completed = 'completed';
    case Overdue = 'overdue';
}
