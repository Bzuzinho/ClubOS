<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>{{ $submission->type === 'registration' ? 'Nova pré-inscrição' : 'Novo pedido de contacto' }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #172033; line-height: 1.5;">
    <h1 style="font-size: 22px; margin-bottom: 8px;">
        {{ $submission->type === 'registration' ? 'Nova pré-inscrição' : 'Novo pedido de contacto' }}
    </h1>
    <p style="margin-top: 0; color: #596579;">Recebido através do website BSCN em {{ $submission->created_at?->timezone('Europe/Lisbon')->format('d/m/Y H:i') }}.</p>

    <table cellpadding="7" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 720px;">
        @foreach ([
            'Nome do atleta' => $submission->athlete_name,
            'Data de nascimento' => $submission->birth_date?->format('d/m/Y'),
            'Email' => $submission->email,
            'Telefone' => $submission->phone,
            'Localidade' => $submission->locality,
            'Programa' => $submission->program,
            'Experiência' => $submission->experience,
            'Clube anterior' => $submission->previous_club,
            'Número de federação' => $submission->federation_number,
            'Disponibilidade' => $submission->availability,
            'Encarregado de educação' => $submission->guardian_name,
            'Relação' => $submission->guardian_relationship,
            'Email do encarregado' => $submission->guardian_email,
            'Telefone do encarregado' => $submission->guardian_phone,
            'Notas' => $submission->notes,
        ] as $label => $value)
            @if ($value !== null && $value !== '')
                <tr>
                    <th align="left" style="width: 220px; border-bottom: 1px solid #d9deea; background: #f6f8fc;">{{ $label }}</th>
                    <td style="border-bottom: 1px solid #d9deea;">{{ $value }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    @if ($submission->user_id)
        <p><strong>Ficha criada/associada:</strong> {{ $submission->user_id }}</p>
    @endif

    <p style="margin-top: 24px;">
        <a href="{{ url('/website/pedidos/'.$submission->id) }}" style="background: #0b4b8c; color: #ffffff; padding: 10px 16px; border-radius: 7px; text-decoration: none;">Abrir pedido no ClubOS</a>
    </p>
</body>
</html>
