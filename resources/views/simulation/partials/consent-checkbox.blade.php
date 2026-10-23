<div class="simulation-consent">
    <input type="checkbox" name="aceite_termos" value="1" id="aceite_termos" @checked(old('aceite_termos')) required>

    <label for="aceite_termos">
        @if (config('branding.active') === 'tcc')
            Autorizo o uso dos dados pela concessionária NVS e pela empresa vinculada para cadastro e acompanhamento no CRM, incluindo comunicações por e-mail e mensagens.
            @if (config('features.insurance_analysis.enabled', false))
                Autorizo também o compartilhamento com empresas fornecedoras de serviços para verificação de crédito e análise, incluindo biometria e comunicação dos resultados.
            @endif
        @else
        Autorizo o compartilhamento dos dados com o corretor de seguros e companhias de seguros,
        para verificação de crédito e análise de seguro aluguel. Estou ciente que meus dados podem
        ser utilizados para envio de e-mails, mensagens, biometria e resultados da análise.
        @endif
        <a href="#" target="_blank">Política de privacidade</a>
    </label>
</div>
