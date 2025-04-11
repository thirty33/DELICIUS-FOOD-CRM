<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserCredentialsEmail extends Mailable
{
    use Queueable, SerializesModels;
    
    protected $logoBase64;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Sus credenciales de acceso - Delicius Food',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.user-credentials',
            with: [
                'email' => $this->user->email,
                'nickname' => $this->user->nickname,
                'password' => $this->user->plain_password,
                'logo' => config('app.LOGO_URL'),
            ],
        );
    }

    /**
     * No necesitamos adjuntar nada
     */
    public function attachments(): array
    {
        return [];
    }
}