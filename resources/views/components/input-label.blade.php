{{-- 
  Componente: Label para Input
  
  Label HTML com estilos padrao Tailwind.
  Aceita propriedade 'value' ou conteudo via slot.
  Usa fonte media e cor cinza.
  
  Uso: <x-input-label for="email" value="E-mail" />
--}}

@props(['value'])

{{-- Label com texto cinza-700 e fonte media --}}
<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-700']) }}>
    {{ $value ?? $slot }}
</label>

