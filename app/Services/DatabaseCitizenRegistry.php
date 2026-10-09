<?php

namespace App\Services;

use App\Contracts\CitizenRegistry;
use App\Models\RegistryCitizen;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

class DatabaseCitizenRegistry implements CitizenRegistry
{
    public function __construct(private NationalIdentity $identity) {}

    public function lookup(#[\SensitiveParameter] string $nationalId): ?CitizenIdentity
    {
        $this->assertSimulationAllowed();

        return $this->record(RegistryCitizen::query()->where('national_id_hash', $this->identity->fingerprint($nationalId, true))->first());
    }

    public function find(string $subjectId): ?CitizenIdentity
    {
        $this->assertSimulationAllowed();

        return $this->record(RegistryCitizen::query()->find($subjectId));
    }

    private function assertSimulationAllowed(): void
    {
        if (! app()->environment(['local', 'testing']) || config('identity.driver') !== 'mock_database') {
            throw new LogicException('The simulated citizen registry is restricted to local development and tests.');
        }
        if (config('database.default') === 'registry'
            || (config('database.connections.registry.driver') === 'mysql'
                && config('database.connections.registry.database') === config('database.connections.'.config('database.default').'.database'))) {
            throw new LogicException('The simulated registry must use a separate database.');
        }
    }

    private function record(?RegistryCitizen $citizen): ?CitizenIdentity
    {
        if (! $citizen?->is_active) {
            return null;
        }

        $email = Str::lower(trim($citizen->email));
        $data = ['subject_id' => $citizen->id, 'national_id' => $citizen->national_id, 'full_name' => $citizen->full_name,
            'email' => $email, 'governorate' => $citizen->governorate];

        if (Validator::make($data, [
            'subject_id' => ['required', 'uuid'],
            'national_id' => ['required', 'string', 'regex:/\\A[0-9]{10}\\z/'],
            'full_name' => ['required', 'string', 'max:80', 'regex:/\\A[^\\p{C}<>]+\\z/u'],
            'email' => ['required', 'string', 'email', 'max:254', 'not_regex:/[\\r\\n]/'],
            'governorate' => ['required', Rule::in(config('moshkelti.governorates'))],
        ])->fails() || ! hash_equals($citizen->national_id_hash, $this->identity->fingerprint($data['national_id'], true))) {
            throw new LogicException('The registry returned an invalid identity record.');
        }

        return new CitizenIdentity($citizen->id, $data['national_id'], $data['full_name'], $email, $data['governorate']);
    }
}
