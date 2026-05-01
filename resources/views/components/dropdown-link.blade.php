{{-- 
  Componente: Link dentro de Dropdown
  
  Link para ser usado dentro de um menu dropdown.
  Estilos: texto cinza com hover em fundo cinza claro.
  
  Uso: <x-dropdown-link href=\"/profile\">Perfil</x-dropdown-link>
--}}

{{-- Link com estilos para dropdown menu --}}
<a {{ $attributes->merge(['class' => 'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out']) }}>{{ $slot }}</a>
