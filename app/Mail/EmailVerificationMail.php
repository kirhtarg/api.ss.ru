<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public $verificationUrl;

    public $siteInfo;

    public $bonusAmount;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, string $verificationUrl, array $siteInfo = [])
    {
        $this->user = $user;
        $this->verificationUrl = $verificationUrl;
        $this->siteInfo = $siteInfo;

        // Получаем информацию о приветственных бонусах
        $bonusesAtReg = \App\Models\Setting::where('key', 'bonuses_at_reg')->first();
        $this->bonusAmount = ($bonusesAtReg && $bonusesAtReg->value && $bonusesAtReg->value > 0)
            ? (int) $bonusesAtReg->value
            : 0;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $siteName = $this->siteInfo['site_name'] ?? config('app.name');

        return new Envelope(
            to: $this->user->email,
            subject: "Подтверждение регистрации на {$siteName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.email-verification',
            with: [
                'user' => $this->user,
                'verificationUrl' => $this->verificationUrl,
                'siteInfo' => $this->siteInfo,
                'bonusAmount' => $this->bonusAmount,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
