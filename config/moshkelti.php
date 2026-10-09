<?php

return [
    'municipality' => 'Amman pilot municipality',
    'categories' => ['roads' => 'Roads / الطرق', 'lighting' => 'Lighting / الإنارة', 'waste' => 'Waste / النفايات', 'drainage' => 'Drainage / الصرف الصحي', 'public_facilities' => 'Public facilities / المرافق العامة'],
    'governorates' => ['Amman', 'Irbid', 'Zarqa', 'Balqa', 'Madaba', 'Jerash', 'Ajloun', 'Mafraq', 'Karak', 'Tafilah', 'Maan', 'Aqaba'],
    'ai_driver' => env('MOSHKELTI_AI_DRIVER', 'local'),
    'ai_endpoint' => env('MOSHKELTI_AI_ENDPOINT'),
    'ai_host' => env('MOSHKELTI_AI_HOST'),
    'ai_token' => env('MOSHKELTI_AI_TOKEN'),
    'ai_daily_budget' => (int) env('MOSHKELTI_AI_DAILY_BUDGET', 200),
    'scanner_binary' => env('MOSHKELTI_SCANNER_BINARY'),
    'require_scanner' => env('MOSHKELTI_REQUIRE_SCANNER', false),
    'attachment_limit' => 3,
    'attachment_max_kb' => 5120,
    'retention_days' => (int) env('MOSHKELTI_RETENTION_DAYS', 180),
    'quarantine_hours' => 24,
    'dispatch_mode' => 'simulated',
];
