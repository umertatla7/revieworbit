<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomPlanRequested extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array{contact_name:string,contact_email:string,business_name:string,locations:int,monthly_messages:int} $details */
    public function __construct(private readonly array $details) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('B Review custom plan request: '.$this->details['business_name'])
            ->greeting('A customer requested a custom B Review plan.')
            ->line('Business: '.$this->details['business_name'])
            ->line('Contact: '.$this->details['contact_name'].' ('.$this->details['contact_email'].')')
            ->line('Locations requested: '.number_format($this->details['locations']))
            ->line('Monthly SMS requested: '.number_format($this->details['monthly_messages']))
            ->line('No automatic price was shown to the customer.');
    }
}
