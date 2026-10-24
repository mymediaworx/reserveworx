<?php

namespace App\Enums;

enum CustomerDataExportStatus: string
{
    case Queued = 'queued';
    case Completed = 'completed';
    case Failed = 'failed';
}
