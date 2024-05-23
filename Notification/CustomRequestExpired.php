<?php

namespace App\Notifications\Payer\CustomVideoRequest;

use App\CustomVideoRequest;
use App\Notifications\BaseNotification;
use App\Services\SendgridService;
use Illuminate\Support\Str;

class CustomRequestExpired extends BaseNotification
{
    protected $profileSetName = 'custom_video_request';
    protected $asmGroup = SendgridService::ASM_GROUP_CUSTOM_REQUEST;

    public function __construct(
        protected CustomVideoRequest $customRequest,
        protected                    $channels = null)
    {
    }

    public function via($notifiable)
    {
        if ($this->channels) return $this->channels;

        $preferredChannels = $this->preferredChannels($notifiable);
        $preferredChannels[] = 'database';

        return $preferredChannels;
    }

    public function toSendGrid($notifiable)
    {
        return $this->sendgridSend($notifiable,
            config('sendgrid.templates.user.' . Str::snake(class_basename($this), '-')),
            [
                'orientation' => getCurrentUserOrientation(),
                'creatorName' =>  ucfirst($this->customRequest->creator?->model?->title) ?? 'creator',
                'amount' => $this->customRequest->amount,
            ]
        );
    }

    public function toArray($notifiable)
    {
        $creatorName = ucfirst($this->customRequest->creator?->model?->title) ?? 'creator';
        $creatorUrl = $this->customRequest->creator?->model?->creator_url;

        return [
            'type' => 'custom-video-request-expired-notification',
            'icon' => '/custom-video-icon.png',
            'title' => 'Custom Video Request Update',
            'content' => 'Unfortunately your Custom Video Request to <a href="' . $creatorUrl . '">' . $creatorName . '</a> was not completed by the creator. We have refunded the Custom Video amount to your wallet balance.',
            'custom_video_request' => $this->customRequest,
        ];
    }
}
