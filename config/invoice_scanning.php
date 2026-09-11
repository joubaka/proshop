<?php

return [
    'enabled' => env('INVOICE_SCANNING_ENABLED', false),
    'driver' => env('INVOICE_SCANNING_DRIVER', 'openai'),
    'disk' => env('INVOICE_SCANNING_DISK', 'invoice_scans'),
    'max_file_kb' => (int) env('INVOICE_SCANNING_MAX_FILE_KB', 12288),
    'max_files' => (int) env('INVOICE_SCANNING_MAX_FILES', 10),
    'max_total_file_kb' => (int) env('INVOICE_SCANNING_MAX_TOTAL_FILE_KB', 30720),
    'price_change_warning_percent' => (float) env('INVOICE_PRICE_WARNING_PERCENT', 10),
    'match_threshold' => (float) env('INVOICE_MATCH_THRESHOLD', 0.88),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_INVOICE_MODEL', 'gpt-5.4-mini'),
        'timeout' => (int) env('OPENAI_INVOICE_TIMEOUT', 90),
    ],
];
