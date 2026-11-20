@php($analysisEnabled = config('features.insurance_analysis.enabled', false))
@php($simulationActionLabel = $analysisEnabled ? 'Iniciar simulação' : 'Iniciar solicitação')

<section id="inicio" class="nvs-hero" aria-labelledby="nvs-title" tabindex="-1">
    <div class="nvs-wrap nvs-hero-grid">
        <div class="nvs-intro" data-enter>
            <p class="nvs-kicker">Seguro para aluguel</p>
            <h1 id="nvs-title">Um cadastro.<br><em>{{ $analysisEnabled ? 'Várias cotações.' : 'Tudo organizado.' }}</em></h1>
            <p class="nvs-lede">
                {{ $analysisEnabled
                    ? 'Preencha os dados uma vez. A corretora consulta as seguradoras e reúne os valores para você comparar as opções para o seu aluguel.'
                    : 'Envie os dados do imóvel e do interessado em um só formulário. A corretora recebe a solicitação e acompanha o atendimento.' }}
            </p>
            <div class="nvs-hero-actions flex flex-wrap items-center">
                <a href="{{ route('simulation.start') }}" class="nvs-button nvs-arrow" target="_blank" rel="noopener noreferrer"
                   aria-label="{{ $simulationActionLabel }} (abre em nova guia)">{{ $simulationActionLabel }}</a>
                <a href="#solucoes" class="nvs-link nvs-down">Como funciona</a>
            </div>
        </div>

        <fieldset class="nvs-model" aria-describedby="model-help">
            <legend class="sr-only">Maquete por tipo de imóvel</legend>
            <span id="model-help" class="sr-only">Escolha o tipo de imóvel para visualizar sua maquete.</span>
            <input class="sr-only" type="radio" id="modelo-residencial" name="modelo-imovel" aria-controls="maquete-residencial" checked>
            <input class="sr-only" type="radio" id="modelo-comercial" name="modelo-imovel" aria-controls="maquete-comercial">
            <div class="nvs-stage">
                @foreach (['residencial' => 'Maquete de uma casa com varanda, jardim e piscina.', 'comercial' => 'Maquete de um edifício comercial com fachada envidraçada e terraço.'] as $model => $description)
                    <img id="maquete-{{ $model }}" class="nvs-model-{{ $model }}" src="{{ asset("imgs/nvs-maquete-{$model}.webp") }}"
                         srcset="{{ asset("imgs/nvs-maquete-{$model}-680.webp") }} 680w, {{ asset("imgs/nvs-maquete-{$model}.webp") }} 1200w"
                         sizes="(min-width: 1440px) 760px, (min-width: 900px) 54vw, 100vw"
                         width="1200" height="920" alt="{{ $description }}"
                         @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                @endforeach
            </div>
            <div class="nvs-model-switch">
                <label for="modelo-residencial">Residencial</label>
                <label for="modelo-comercial">Comercial</label>
            </div>
        </fieldset>
    </div>
    @if ($analysisEnabled)
        <div class="nvs-comparison-strip">
            <p class="nvs-wrap">Compare as parcelas <em>lado a lado.</em></p>
        </div>
    @endif
</section>

<section id="solucoes" class="nvs-workflow" aria-labelledby="workflow-title">
    <div class="nvs-wrap nvs-split">
        <div>
            <p class="nvs-kicker">Como funciona</p>
            <h2 id="workflow-title">Do formulário<br><em>ao atendimento.</em></h2>
            <ol class="nvs-steps">
                <li><div><h3>Informe os dados do aluguel</h3><p>O mesmo formulário reúne os dados do interessado, do imóvel e das despesas mensais.</p></div></li>
                <li><div><h3>{{ $analysisEnabled ? 'Consulte diferentes seguradoras' : 'Encaminhe a solicitação' }}</h3><p>{{ $analysisEnabled ? 'A plataforma envia a solicitação às companhias habilitadas. A equipe acompanha cada resposta no painel.' : 'A imobiliária e a corretora encontram a solicitação vinculada ao cadastro do interessado.' }}</p></div></li>
                <li><div><h3>{{ $analysisEnabled ? 'Compare os valores aprovados' : 'Acompanhe o atendimento' }}</h3><p>{{ $analysisEnabled ? 'As parcelas ficam lado a lado para a equipe apresentar as opções disponíveis ao interessado.' : 'O histórico do cadastro ajuda a equipe a retomar o contato e dar continuidade ao pedido.' }}</p></div></li>
            </ol>
        </div>
        <div class="nvs-preview">
            <div class="nvs-sheet">
                <div class="nvs-sheet-head flex items-center justify-between gap-3">
                    <span>Painel da imobiliária</span><span>Exemplo</span>
                </div>
                <div class="nvs-sheet-content">
                    <p class="nvs-sheet-property">Imóvel residencial</p>
                    <h3>{{ $analysisEnabled ? 'Resumo da cotação' : 'Resumo da solicitação' }}</h3>
                    @if ($analysisEnabled)
                        <div class="nvs-price">
                            <span>Menor parcela aprovada</span>
                            <p>R$ 86<small>,40</small><span> / mês</span></p>
                        </div>
                        <dl class="nvs-results">
                            <div><dt>Opção A</dt><dd>R$ 86,40</dd></div>
                            <div><dt>Opção B</dt><dd>R$ 94,80</dd></div>
                            <div><dt>Opção C</dt><dd class="nvs-pending">Em análise</dd></div>
                        </dl>
                    @else
                        <dl class="nvs-results">
                            <div><dt>Tipo de locação</dt><dd>Residencial</dd></div>
                            <div><dt>Origem</dt><dd>Imobiliária</dd></div>
                            <div><dt>Solicitação</dt><dd>Recebida</dd></div>
                        </dl>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>

<section id="recursos" class="nvs-access" aria-labelledby="access-title">
    <div class="nvs-wrap nvs-split">
        <div>
            <p class="nvs-kicker">Áreas de acesso</p>
            <h2 id="access-title">Por onde você<br><em>quer começar?</em></h2>
        </div>
        <div class="nvs-directory">
            @foreach ([
                ['title' => 'Seguro para aluguel', 'action' => $simulationActionLabel, 'text' => 'Para quem vai alugar, é proprietário ou representa uma imobiliária.', 'route' => 'simulation.start'],
                ['title' => 'Primeiro acesso', 'action' => 'Cadastrar minha imobiliária', 'text' => 'Crie o acesso da empresa e receba solicitações pelo seu formulário.', 'route' => 'empresa.register.form'],
                ['title' => 'Imobiliária cadastrada', 'action' => 'Acessar meu painel', 'text' => 'Acompanhe as solicitações e os resultados da sua imobiliária.', 'route' => 'empresa.login'],
            ] as $entry)
                <div class="nvs-directory-row">
                    <h3>{{ $entry['title'] }}</h3>
                    <p id="descricao-acesso-{{ $loop->index }}">{{ $entry['text'] }}</p>
                    <a href="{{ route($entry['route']) }}" class="nvs-button nvs-out{{ $loop->first ? '' : ' nvs-button-outline' }}"
                       aria-describedby="descricao-acesso-{{ $loop->index }}" aria-label="{{ $entry['action'] }} (abre em nova guia)"
                       target="_blank" rel="noopener noreferrer" @if ($entry['route'] !== 'simulation.start') data-auth-access @endif>{{ $entry['action'] }}</a>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section id="suporte" class="nvs-support" aria-labelledby="support-title">
    <div class="nvs-wrap nvs-split">
        <div>
            <p class="nvs-kicker">Suporte</p>
            <h2 id="support-title">Dúvidas<br><em>frequentes.</em></h2>
            <a href="https://wa.me/5511999999999" target="_blank" rel="noopener noreferrer" aria-label="Falar com o suporte (abre em nova guia)" class="nvs-link nvs-out">Falar com o suporte</a>
        </div>
        <div class="nvs-faq">
            <details open><summary>Quem pode enviar uma solicitação?</summary><p>Locatários, proprietários e imobiliárias. Ao iniciar, escolha o perfil de quem está preenchendo o formulário.</p></details>
            <details><summary>Preciso repetir o cadastro para cada seguradora?</summary><p>{{ $analysisEnabled ? 'Basta preencher uma vez. A corretora usa os mesmos dados para consultar as companhias habilitadas para aquele aluguel.' : 'O cadastro é único. Nesta versão, a equipe recebe as solicitações e a consulta automática às seguradoras está desativada.' }}</p></details>
            <details><summary>Onde minha imobiliária acompanha os pedidos?</summary><p>No painel da empresa, com o e-mail e a senha cadastrados. Cada imobiliária vê as solicitações vinculadas a ela.</p></details>
        </div>
    </div>
</section>
