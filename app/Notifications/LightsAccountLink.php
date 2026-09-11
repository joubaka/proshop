<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LightsAccountLink extends Notification
{
    use Queueable;

    public function __construct(private string $purpose, #[\SensitiveParameter] private string $token) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->purpose === 'verify') {
            return (new MailMessage)->subject('Verify your Court Lights account')
                ->line('Confirm your email address before using payments or court controls.')
                ->action('Verify email', route('lights.verify', ['token' => $this->token]))
                ->line('This link expires in 24 hours.');
        }
        return (new MailMessage)->subject('Reset your Court Lights password')
            ->line('Use this link to choose a new password.')
            ->action('Reset password', route('lights.password.reset', ['token' => $this->token]))
            ->line('This link expires in one hour. Ignore it if you did not request a reset.');
    }
}
