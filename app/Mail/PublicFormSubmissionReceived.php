<?php

namespace App\Mail;

use App\Models\PublicFormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublicFormSubmissionReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PublicFormSubmission $submission)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $replyToEmail = $this->submission->guardian_email ?: $this->submission->email;
        $replyToName = $this->submission->guardian_name ?: $this->submission->athlete_name;

        return new Envelope(
            replyTo: [new Address($replyToEmail, $replyToName)],
            subject: $this->submission->type === 'registration'
                ? 'Nova pré-inscrição recebida no website BSCN'
                : 'Novo pedido de contacto recebido no website BSCN',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.public-form-submission-received');
    }
}
