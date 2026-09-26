<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array{name:string,email:string,business_name:string,business_phone:string,industry:string,city:string,region:string,country:string,plan_name:string} $details */
    public function __construct(private readonly array $details) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New B Review registration: '.$this->details['business_name'])
            ->greeting('A new customer registered for B Review.')
            ->line('Business: '.$this->details['business_name'])
            ->line('Owner: '.$this->details['name'].' ('.$this->details['email'].')')
            ->line('Phone: '.$this->details['business_phone'])
            ->line('Industry: '.str_replace('_', ' ', $this->details['industry']))
            ->line('Location: '.$this->details['city'].', '.$this->details['region'].', '.$this->details['country'])
            ->line('Selected plan: '.$this->details['plan_name']);
    }
}
