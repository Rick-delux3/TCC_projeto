{{-- 
  Componente: Input de Texto
  
  Input HTML customizado com estilos Tailwind CSS.
  Aceita propriedade 'disabled' para desabilitar o campo.
  Suporta merge de atributos para maior flexibilidade.
  
  Uso: <x-text-input name="email" type="email" />
--}}

@props(['disabled' => false])

{{-- Input com estilos padrao e tema indigo para focus --}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) }}>

