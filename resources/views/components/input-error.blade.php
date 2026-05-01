{{-- 
  Componente: Mensagem de Erro
  
  Exibe lista de mensagens de erro em vermelho.
  Aceita propriedade 'messages' (string ou array).
  Renderiza apenas se houver mensagens.
  
  Uso: <x-input-error :messages="$errors->get('email')" />
--}}

@props(['messages'])

{{-- Lista de erros em vermelho com espacamento --}}
@if ($messages)
    <ul {{ $attributes->merge(['class' => 'text-sm text-red-600 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            {{-- Cada erro como item de lista --}}
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif

