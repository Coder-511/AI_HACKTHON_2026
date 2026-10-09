<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReportStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $reportId, public string $status) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['report_id' => $this->reportId, 'status' => $this->status];
    }
}
