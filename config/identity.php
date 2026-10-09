<?php

return [
    'sms_driver' => env('IDENTITY_SMS_DRIVER', 'local'),
    'driver' => env('IDENTITY_DRIVER', 'mock_database'),
    'lookup_key' => env('IDENTITY_LOOKUP_KEY'),
    'registry_lookup_key' => env('REGISTRY_LOOKUP_KEY'),
];
