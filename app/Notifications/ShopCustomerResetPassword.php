<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

class ShopCustomerResetPassword extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        return route('shop.account.password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
    }
}
