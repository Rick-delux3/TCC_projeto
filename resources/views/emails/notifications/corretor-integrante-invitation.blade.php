@include('emails.partials.action-message', [
    'subject' => $subject,
    'preheader' => $isResend
        ? 'Seu novo convite de equipe está pronto. O link anterior não é mais válido.'
        : \App\Support\BrandingText::choose('Você recebeu um convite para integrar a equipe da corretora.', 'Você recebeu um convite para integrar a equipe da concessionária.'),
    'eyebrow' => $isResend ? 'Novo convite de equipe' : 'Convite de equipe',
    'heading' => $isResend
        ? 'Seu novo convite está pronto'
        : 'Você foi convidado para fazer parte da equipe',
    'greeting' => filled($recipientName) ? "Olá, {$recipientName}!" : 'Olá!',
    'introduction' => $isResend
        ? 'Um novo convite foi gerado. Qualquer link enviado anteriormente deixou de ser válido.'
        : \App\Support\BrandingText::choose('Você foi convidado para integrar a equipe da corretora.', 'Você foi convidado para integrar a equipe da concessionária.'),
    'badgeLabel' => 'ID',
    'panelKicker' => 'Painel interno',
    'panelTitle' => \App\Support\BrandingText::choose('Acesso para corretor integrante', 'Acesso para integrante da concessionária'),
    'actionText' => 'Acessar convite',
    'actionUrl' => $invitationUrl,
    'expirationLabel' => 'Este convite expira em',
    'expirationValue' => $expirationValue,
    'helperText' => 'Caso expire, solicite ao CEO um novo envio.',
    'footerLabel' => \App\Support\BrandingText::choose('Portal imobiliário', 'Portal empresarial'),
])
