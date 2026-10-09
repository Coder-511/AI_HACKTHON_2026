<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Log;
use LogicException;

class LocalSmsChannel
{
    public function send(object $notifiable, IdentityVerificationCode $notification): void
    {
        if (! app()->environment(['local', 'testing']) || config('identity.sms_driver') !== 'local') {
            throw new LogicException('A real SMS provider must be configured; local SMS simulation is disabled.');
        }
        $mobile = $notifiable->routeNotificationFor('sms');
        if (! is_string($mobile) || ! preg_match('/\\A\\+9627[0-9]{8}\\z/', $mobile)) {
            throw new LogicException('Invalid SMS destination.');
        }
        Log::channel('sms')->debug('SIMULATED SMS — no message sent. Registration OTP: '.$notification->code, [
            'destination_suffix' => substr($mobile, -4),
        ]);
    }
}
