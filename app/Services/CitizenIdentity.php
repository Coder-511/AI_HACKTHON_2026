<?php

namespace App\Services;

readonly class CitizenIdentity
{
    public function __construct(
        public string $subjectId,
        #[\SensitiveParameter] public string $nationalId,
        #[\SensitiveParameter] public string $fullName,
        #[\SensitiveParameter] public string $email,
        public string $governorate,
    ) {}
}
