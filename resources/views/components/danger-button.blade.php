{{-- 
  Componente: Botao Perigo
  
  Botao com estilo de alerta (vermelho) para acoes perigosas (delete, etc).
  Tipo submit por padrao, com efeitos hover e active em tons de vermelho.
  
  Uso: <x-danger-button>Deletar</x-danger-button>
--}}

{{-- Botao com fundo vermelho-600, texto branco, hover em vermelho-500 --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>

