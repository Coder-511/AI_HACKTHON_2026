<?php

namespace App\Jobs;

use App\Services\AuthorityDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(public string $outboxId) {}

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(AuthorityDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->outboxId);
    }
}
