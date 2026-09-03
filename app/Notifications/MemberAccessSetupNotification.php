<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class MemberAccessSetupNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        if ($notifiable instanceof User) {
            $actor = auth()->user();
            $actor = $actor instanceof User ? $actor : null;

            $configuration = app(PlatformAccessService::class)->grantPlatformAccess(
                $notifiable,
                $actor,
                'Acesso concedido pelo envio do convite de configuração de palavra-passe.',
            );

            $configuration->forceFill([
                'ultimo_envio_acessos_at' => now(),
            ])->save();
        }

        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ], false));

        return (new MailMessage)
            ->subject('Acesso ao ClubOS')
            ->greeting('Bem-vindo ao ClubOS')
            ->line('Foi criado ou atualizado o seu acesso ao ClubOS.')
            ->line('Clique no botão abaixo para definir a sua palavra-passe e concluir o acesso à aplicação.')
            ->action('Definir palavra-passe', $url)
            ->line('Este link expira em 60 minutos.')
            ->line('Se não pediu este acesso, pode ignorar este email.');
    }
}
