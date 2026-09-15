@include('emails.partials.action-message', [
    'subject' => $subject,
    'preheader' => $isResend
        ? 'Seu novo convite de equipe está pronto. O link anterior não é mais válido.'
        : 'Você recebeu um convite para integrar a equipe da corretora.',
    'eyebrow' => $isResend ? 'Novo convite de equipe' : 'Convite de equipe',
    'heading' => $isResend
        ? 'Seu novo convite está pronto'
        : 'Você foi convidado para fazer parte da equipe',
    'greeting' => filled($recipientName) ? "Olá, {$recipientName}!" : 'Olá!',
    'introduction' => $isResend
        ? 'Um novo convite foi gerado. Qualquer link enviado anteriormente deixou de ser válido.'
        : 'Você foi convidado para integrar a equipe da corretora.',
    'badgeLabel' => 'ID',
    'panelKicker' => 'Painel interno',
    'panelTitle' => 'Acesso para corretor integrante',
    'actionText' => 'Acessar convite',
    'actionUrl' => $invitationUrl,
    'expirationLabel' => 'Este convite expira em',
    'expirationValue' => $expirationValue,
    'helperText' => 'Caso expire, solicite ao CEO um novo envio.',
    'footerLabel' => 'Portal imobiliário',
])
