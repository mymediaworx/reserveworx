<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CSV import
    |--------------------------------------------------------------------------
    |
    | Each queued job writes one chunk, then queues the next. Shared hosting
    | stops the worker after a short max-time, so a chunk stays small.
    |
    */

    'chunk' => 100,

    'max_kilobytes' => 8192,

    'max_rows' => 100_000,

    'max_columns' => 50,

    'preview_rows' => 20,

    'skipped_limit' => 100,

];
