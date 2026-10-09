<?php

namespace App\Console\Commands;

use App\Contracts\CitizenRegistry;
use App\Services\DatabaseCitizenRegistry;
use Illuminate\Console\Command;

class SecurityReadiness extends Command
{
    protected $signature = 'moshkelti:security-check';

    protected $description = 'Check production prerequisites without exposing credentials';

    public function handle(): int
    {
        $checks = [
            'Production environment' => app()->isProduction(),
            'Debug disabled' => ! config('app.debug'),
            'HTTPS app URL' => str_starts_with(config('app.url'), 'https://'),
            'Secure cookies' => (bool) config('session.secure'),
            'HTTP-only cookies' => (bool) config('session.http_only'),
            'SameSite protection' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'Encrypted sessions' => (bool) config('session.encrypt'),
            'MySQL runtime database' => config('database.default') === 'mysql',
            'Non-root DB account' => ! in_array(config('database.connections.mysql.username'), ['root', ''], true),
            'Private storage not auto-served' => ! config('filesystems.disks.local.serve') && ! config('filesystems.disks.private.serve'),
            'Durable queue' => config('queue.default') !== 'sync',
            'Configured malware scanner' => is_file((string) config('moshkelti.scanner_binary')),
            'Real email transport' => ! in_array(config('mail.default'), ['log', 'array'], true),
            'Approved identity provider (mock is local only)' => config('identity.driver') !== 'mock_database' && ! (app(CitizenRegistry::class) instanceof DatabaseCitizenRegistry),
            'Identity lookup key configured' => strlen((string) config('identity.lookup_key')) >= 32,
        ];
        if (config('moshkelti.ai_driver') === 'remote') {
            $checks['AI allowlist and credential configured'] = (bool) config('moshkelti.ai_host') && (bool) config('moshkelti.ai_token') && (bool) config('moshkelti.ai_endpoint');
        }
        foreach ($checks as $name => $passed) {
            $passed ? $this->info('PASS '.$name) : $this->warn('BLOCK '.$name);
        }
        $this->line('Also verify firewall/TLS, least-privilege grants, scanner signatures, encrypted backups/restoration, retention policy, monitoring, AI provider contract and Jordan privacy/legal review.');
        $this->line('Delivery remains simulated. This check is not a security audit or guarantee.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
