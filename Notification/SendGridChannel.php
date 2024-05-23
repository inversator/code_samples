<?php

namespace App\Channels;

use App\Services\SendgridService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SendGridChannel
{
    /**
     * Send the given notification.
     *
     * @param mixed $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        if (!(new SendgridService())->shouldSendEmail($notifiable->email, $notification)) {
            return;
        }

        try {
            $resp = $notification->toSendGrid($notifiable);

            if ($resp?->statusCode() == 400) {
                Log::channel('sendgrid')->error('error', [$resp->statusCode(), $resp->body()]);
            }
        } catch (\Exception $e) {
            Log::channel('sendgrid')->error($e->getMessage());
            report($e);
        }
    }
}
