<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class IdentityVerificationCode extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return [LocalSmsChannel::class];
    }
}
