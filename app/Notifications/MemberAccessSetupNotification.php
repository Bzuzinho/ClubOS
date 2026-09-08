<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class MemberAccessSetupNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('access.activate', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ], false));
        $loginUrl = route('login');
        $displayName = trim((string) ($notifiable->nome_completo ?: $notifiable->name));
        $firstName = Str::of($displayName)->trim()->explode(' ')->first() ?: 'utilizador';
        $expiresInHours = max(1, (int) ceil(((int) config('auth.passwords.member_access.expire', 4320)) / 60));

        return (new MailMessage)
            ->subject('O seu acesso ao BSCN está pronto')
            ->greeting('Olá, '.$firstName.'!')
            ->line('Foi criado o seu acesso à área pessoal do Benedita Sport Club Natação.')
            ->line('1. Carregue no botão abaixo.')
            ->line('2. Escolha uma palavra-passe.')
            ->line('3. Entre diretamente na sua área pessoal.')
            ->action('Criar o meu acesso', $url)
            ->line("O link é válido durante {$expiresInHours} horas e só pode ser utilizado uma vez.")
            ->line("Pode utilizar sempre a plataforma no browser do telemóvel ou computador através de {$loginUrl}. Não precisa de instalar nenhuma aplicação.")
            ->line('Depois de entrar, poderá opcionalmente colocar um ícone BSCN no ecrã principal do telemóvel.')
            ->line('Se não pediu este acesso, pode ignorar este email.');
    }
}
