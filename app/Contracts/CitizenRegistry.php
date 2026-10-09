<?php

namespace App\Contracts;

use App\Services\CitizenIdentity;

interface CitizenRegistry
{
    public function lookup(#[\SensitiveParameter] string $nationalId): ?CitizenIdentity;

    public function find(string $subjectId): ?CitizenIdentity;
}
