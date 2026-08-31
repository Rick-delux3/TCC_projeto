@include('emails.partials.two-factor-message', [
    'subject' => 'Seu código de verificação',
    'preheader' => "Use o código {$code} para concluir seu acesso com segurança.",
    'eyebrow' => 'Segurança',
    'heading' => 'Verificação em duas etapas',
    'greeting' => 'Olá,',
    'introduction' => 'Use o código abaixo para concluir seu acesso ao portal:',
    'warningTitle' => 'Não compartilhe este código com ninguém.',
    'warningText' => 'Se você não solicitou este acesso, ignore este e-mail e altere sua senha.',
    'footerLabel' => 'Portal imobiliário',
])
