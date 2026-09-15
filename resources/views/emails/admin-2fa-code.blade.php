@include('emails.partials.two-factor-message', [
    'subject' => 'Código de verificação administrativa',
    'preheader' => "Use o código {$code} para confirmar seu primeiro acesso.",
    'eyebrow' => 'Acesso seguro',
    'heading' => 'Confirme seu acesso',
    'greeting' => 'Olá, '.($admin->name ?? 'corretor').'.',
    'introduction' => 'Use o código abaixo para confirmar sua identidade e continuar no painel administrativo:',
    'warningTitle' => 'Este código é pessoal e intransferível.',
    'warningText' => 'Não reconhece esta tentativa? Ignore esta mensagem e entre em contato com o administrador.',
    'footerLabel' => 'Painel administrativo',
])
