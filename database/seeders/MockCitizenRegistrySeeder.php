<?php

namespace Database\Seeders;

use App\Models\RegistryCitizen;
use App\Services\NationalIdentity;
use Illuminate\Database\Seeder;
use LogicException;

class MockCitizenRegistrySeeder extends Seeder
{
    public function run(NationalIdentity $identity): void
    {
        if (! app()->environment(['local', 'testing']) || config('identity.driver') !== 'mock_database') {
            throw new LogicException('Synthetic citizen records may only be seeded in local development or tests.');
        }
        if (config('database.connections.registry.driver') === 'mysql'
            && config('database.connections.registry.database') === config('database.connections.mysql.database')) {
            throw new LogicException('The synthetic registry must be a separate database.');
        }

        foreach ([
            ['0000000001', 'مواطن تجريبي أول', 'citizen1@example.test', 'Amman', true],
            ['0000000002', 'مواطنة تجريبية ثانية', 'citizen2@example.test', 'Irbid', true],
            ['0000000003', 'مواطن تجريبي ثالث', 'citizen3@example.test', 'Zarqa', true],
            ['0000000004', 'سجل تجريبي غير نشط', 'inactive@example.test', 'Amman', false],
        ] as [$nationalId, $name, $email, $governorate, $active]) {
            $fingerprint = $identity->fingerprint($nationalId, true);
            $citizen = RegistryCitizen::query()->where('national_id_hash', $fingerprint)->first() ?? new RegistryCitizen;
            $citizen->forceFill([
                'national_id_hash' => $fingerprint, 'national_id' => $nationalId, 'full_name' => $name,
                'email' => $email, 'mobile_number' => '+96279000000'.substr($nationalId, -1), 'governorate' => $governorate, 'is_active' => $active,
            ])->save();
        }
    }
}
