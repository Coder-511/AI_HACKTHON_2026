<?php

use App\Models\IdentityRegistrationChallenge;
use Illuminate\Support\Facades\Schedule;

Schedule::command('moshkelti:retention')->dailyAt('03:00')->withoutOverlapping();
Schedule::call(fn () => IdentityRegistrationChallenge::query()->where('expires_at', '<', now()->subDay())->delete())->hourly();
