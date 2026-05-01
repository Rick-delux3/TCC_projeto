{{-- 
  Componente: Mensagem de Status da Sessao
  
  Exibe mensagem de status de autenticacao em verde.
  Renderiza apenas se houver mensagem disponivel.
  
  Props: status (string)
  Uso: <x-auth-session-status :status=\"session('status')\" />
--}}

@props(['status'])

{{-- Mensagem de status em verde se existir --}}
@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-green-600']) }}>
        {{ $status }}
    </div>
@endif
