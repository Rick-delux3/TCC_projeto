{{-- 
  Componente: Botao Secundario
  
  Botao com estilo secundario (branco com borda).
  Tipo button por padrao, com hover em cinza claro.
  Usa transicoes suaves e oferece estado disabled.
  
  Uso: <x-secondary-button>Cancelar</x-secondary-button>
--}}

{{-- Botao com fundo branco, borda cinza, hover em cinza-50 --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>

