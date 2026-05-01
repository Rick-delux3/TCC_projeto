{{-- 
  Componente: Link de Navegacao Responsivo
  
  Link para menus responsivos/mobile. Muda aparencia para ativo/inativo.
  Ativo: borda esquerda indigo, fundo indigo claro, texto indigo
  Inativo: borda esquerda transparente, texto cinza
  
  Props: active (booleano)
  Uso: <x-responsive-nav-link :active=\"request()->routeIs('profile')\">Perfil</x-responsive-nav-link>
--}}

@props(['active'])

{{-- Estilos para menu mobile com borda esquerda --}}
@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 text-start text-base font-medium text-indigo-700 bg-indigo-50 focus:outline-none focus:text-indigo-800 focus:bg-indigo-100 focus:border-indigo-700 transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
