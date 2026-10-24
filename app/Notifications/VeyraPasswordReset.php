<?php

namespace App\Notifications;

use App\Services\LanguageRegistry;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Localized reset mail with a link anchored to configured APP_URL, not request Host. */
class VeyraPasswordReset extends Notification
{
    public function __construct(public readonly string $token, string $locale)
    {
        // Notification already owns this mutable property; use its contract.
        $this->locale = $locale;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $messages = app(LanguageRegistry::class)->messages($this->locale);
        $text = static fn (string $key): string => $messages['server.'.$key] ?? $key;
        $url = rtrim((string) config('app.url'), '/')
            .'/#/reset-password?token='.rawurlencode($this->token)
            .'&email='.rawurlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage())
            ->subject($text('password_mail_subject'))
            ->greeting($text('password_mail_greeting'))
            ->line($text('password_mail_intro'))
            ->action($text('password_mail_action'), $url)
            ->line(str_replace(':minutes', (string) config('auth.passwords.users.expire', 60), $text('password_mail_expiration')))
            ->line($text('password_mail_outro'));
    }
}
