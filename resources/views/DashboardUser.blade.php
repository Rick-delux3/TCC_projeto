@extends('layout-inicial.Dashboard_User')

@section('content_w')
<div class="back">
    <h2>Bem-vindo, Imobiliária!</h2>
    <p>Abaixo estão os leads que o sistema sincronizou do LeadLovers:</p>

    <table>
        <thead>
            <tr>
                <th>ID do Banco</th>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Telefone</th>
                <th>Tags Originais (LeadLovers)</th>
                <th>Data de Entrada</th>
            </tr>
        </thead>
        <tbody>
            {{-- O Laravel vai repetir essa linha para cada Lead que encontrar no banco --}}
            @forelse ($leads as $lead)
                <tr>
                    <td>{{ $lead->id }}</td>
                    <td>{{ $lead->name }}</td>
                    <td>{{ $lead->email }}</td>
                    <td>{{ $lead->phone ?? 'Sem telefone' }}</td>
                    <td>
                        <span class="tag">{{ $lead->tags_originais }}</span>
                    </td>
                    <td>{{ $lead->created_at->format('d/m/Y H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; padding: 20px;">
                        Nenhum lead encontrado com a tag desta imobiliária.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    
</div>
@endsection