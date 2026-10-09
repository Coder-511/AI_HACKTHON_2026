<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IdentityVerificationCode extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $code) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Moshkelti registration verification')
            ->line('Your registration verification code: '.$this->code)
            ->line('This code expires in five minutes. Never share it with anyone.')
            ->line('If you did not request this account, ignore this message.')
            ->line('Local pilot: this message uses a synthetic citizen registry, not an official government connection.');
    }
}
