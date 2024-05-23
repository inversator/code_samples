<?php

namespace App\Notifications;

use App\Channels\SendGridChannel;
use App\Services\SendgridService;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $from = 'content@sinparty.com';
    protected $profileSetName = '';
    protected $asmGroup = null;


    /**
     * Set queue name. Warning! Doesn't work on 6.x laravel version!
     */
    public function viaQueues()
    {
        return [
            SendGridChannel::class => 'API',
            'mail' => 'API',
            'slack' => 'API'
        ];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [SendGridChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }

    /**
     * @param $notifiable
     * @param $templateId
     * @param array|null $data
     * @return \SendGrid\Response
     * @throws \SendGrid\Mail\TypeException
     */
    public function sendgridSend(User $notifiable, $templateId, ?array $data)
    {
        $calledNotification = get_called_class();
        $type = str_contains($calledNotification, 'Dashboard') ? 'creator' : 'user';

        $defaultData = [
            'Sender_Name' => ucfirst($notifiable->username),
            'Sender_Email' => $notifiable['email'],
            'username' => ucfirst($notifiable->username),
            'site_url' => config('app.website_url'),
            'dashboard_url' => config('app.dashboard_url'),
            'unsubscribe_link' => generateUnsubscribeLinkByEmail($notifiable['email']),
            'type' => $type,
            'asm_group' => $this->asmGroup
        ];

        Log::channel('sendgrid')->info('Try send', [
            class_basename($this),
            $templateId,
            $notifiable->username,
            'data' => $defaultData + $data
        ]);

        return (new SendgridService())
            ->sendWithTemplate($notifiable, $templateId, $defaultData + $data, $this->from);
    }

    protected function preferredChannels(User $user, array $channels = [SendGridChannel::class])
    {
        $preferredChannels = ['database'];

        if ($user->profileNotifications?->email_enabled
            && $this->profileSetName
            && $user->profileNotifications?->{'email_' . $this->profileSetName}) {

            $preferredChannels[] = SendGridChannel::class;
        }

        return array_intersect($channels, $preferredChannels);
    }
}
