# Estudo das APIs de Fiança Locatícia

## Regra geral — pessoa jurídica sem cônjuge — 02/11/2026

**Decisão expressa do usuário, válida para todas as seguradoras e origens de formulário:** quando o documento do pretendente for **CNPJ**, analisar **somente a empresa**. Não existe cenário PJ com cônjuge. Esta regra prevalece sobre qualquer dúvida ou orientação histórica sobre análise individual/conjunta de empresa, responsável e cônjuge.

- Manter CNPJ e razão social como identidade empresarial; não substituir pelo CPF/nome do responsável para realizar análise PF.
- Desconsiderar estado civil para PJ. Na futura implementação dos formulários, ocultar e deixar de exigir estado civil e campos de cônjuge quando houver CNPJ. No backend, não usar esses dados nos payloads ou na criação de cenários PJ, mesmo que enviados manualmente ou presentes em registros antigos.
- Não analisar o responsável separadamente nem seu cônjuge; não criar cenários spouse/joint para PJ nem tentativa conjunta após recusa da empresa. Usar somente o cenário empresarial primary.
- Dados do responsável eventualmente exigidos no cadastro/payload, incluindo nome e nascimento obtidos pela API CPF conforme regras já definidas, continuam complementares. Consulta cadastral CPF não equivale a análise de seguro do responsável e não autoriza outro pretendente/cenário ou substituição do CNPJ.
- Duas individuais e conjunta condicional permanecem **exclusivamente para PF com cônjuge**. Ao mudar CPF para CNPJ, desconsiderar estado civil/cônjuge anteriores; mudanças posteriores no cadastro não devem alterar o snapshot de rodadas já iniciadas.
- Um cenário empresarial não significa um único plano: Pottencial mantém Basic/Complete/Traditional para a mesma empresa (até três cotações, sem multiplicação por cônjuge); Porto mantém uma criação empresarial e as ofertas retornadas; Too mantém sua análise empresarial conforme elegibilidade. Tokio continua sem análise automática PJ e com o tratamento manual já definido; não analisar o responsável como PF para contornar a restrição.
- Painel, e-mail e comparação devem refletir as tentativas da empresa e seus planos/ofertas, sem análises de responsável/cônjuge ou conjunta PJ.

**Composição PJ esclarecida; implementação e validação pendentes:** aplicar essa condição na validação, snapshot, coordenador, builders e formulários, preservando os pacotes de análise. Somente o estudo foi alterado nesta revisão, sem mudanças em código, cadastros ou histórico.

## Porto — revisão dos três contratos Markdown — 02/11/2026

**Estado:** estudo documental concluído; integração ainda não implementada ou homologada. Esta consolidação prevalece sobre as listas históricas de pendências Porto abaixo. **Atualização de 04/11/2026, confirmada pelo suporte: o PUT de comissão aceita somente ofertas com seus códigos e comissão 15; os demais required são para emissão. Manter uma chamada por oferta e simultaneidade, conforme decisão anterior. A seção 4 substitui as exigências históricas de payload completo.** Foram lidos integralmente os contratos de [criação](Porto_endpoitsMarkdown/Calcula-um-novo-orcamento-para-o-produto-fianca-essencial.md), [ofertas](Porto_endpoitsMarkdown/Exibe-ofertas-disponiveis.md) e [atualização](Porto_endpoitsMarkdown/atualiza-os-dados-de-um-orcamento.md), confrontados com as decisões do usuário, a [arquitetura vigente](ARQUITETURA_ANALISES_SEGURO.md) e os pontos de integração existentes. Nenhum exemplo foi tratado como cotação real ou configuração do cliente.

### 1. Responsabilidade de cada operação

**Fluxo definitivo confirmado pelo usuário em 02/11/2026:** **calcula um novo orçamento (POST) → atualiza os dados do orçamento com comissão de 15% (PUT por oferta, simultâneos) → exibe ofertas (GET após a conclusão das atualizações)**. Esta decisão substitui a proposta anterior de consultar ofertas antes do PUT. Usar as ofertas, formas e condições retornadas na criação para preparar as atualizações; seus preços iniciais são apenas internos e não serão apresentados como resultado final. O GET de ofertas continua sendo a fonte preferencial dos preços recalculados; a consulta do orçamento atualizado é a alternativa a validar nos testes.

Base de negócio: `https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/emissao/aluguel-essencial/v1`. Manter a autenticação já decidida: mesmo token nos headers Authorization Bearer e access_token. Os novos Markdown continuam mostrando somente Authorization e exemplo de erro citando access_token; isso não reabre a decisão.

| Operação | Função no sistema | Evidência e limite |
| --- | --- | --- |
| `POST /orcamentos` | Criar/analisar uma cotação por cenário de pretendentes | Responses declara 201 para sucesso e 409 para recusa, conforme regra confirmada. O texto introdutório que diz 200 continua inconsistente. Persistir `orcamento` retornado antes das próximas etapas |
| `GET /orcamentos/{origem}/{orcamento}/{susep}/ofertas` | Consultar produtos, condições e parcelas disponíveis desse orçamento | 200 confirma a consulta, não uma nova análise cadastral. É a fonte escolhida para preços/exibição, inclusive após eventual ajuste de comissão |
| `PUT /orcamentos/{orcamento}/{susep}` | Atualizar o orçamento existente para uma oferta selecionada e ajustar sua comissão | 200 confirma atualização. Para ajustar comissão, enviar somente ofertas com seguro e comissao.codComissao = 15, conforme suporte em 04/11/2026. Não confundir com reanálise, emissão ou confirmação de pagamento |

`origem = "22"` corresponde à nova contratação, inclusive nossas reanálises por novo POST; "40" é renovação. Preservar orçamento com 15 dígitos e SUSEP interno com seis caracteres, terminando em F/J. O GET não possui body, filtro de comissão ou parâmetro para criar parcelamento. Os caminhos não autorizam deduzir URLs de produção.

### 2. POST: payload mínimo e regras já fechadas

O Markdown de criação declara como obrigatórios `cep`, `coberturas`, `contrato`, `cpfCotador`, `locacaoComercial` (condicional pela descrição), `locatarios`, `susep` e `tipoLocacao`. **Não lista `locador`, `cobranca`, `seguro` ou `ofertas` no body.** Para nosso builder de criação, não transportar requisitos do PUT. A antiga dúvida de locador na criação fica encerrada documentalmente por esta especificação mínima; eventual rejeição real será divergência a validar, não motivo para inventar proprietário.

| Dados | Aplicação definida |
| --- | --- |
| PF residencial | `tipoLocacao = "1"`; omitir locacaoComercial; renda `4 × aluguel` |
| PF comercial | `tipoLocacao = "2"`; capital de abertura `100 × aluguel`; motivo escolhido no futuro seletor comercial; renda `10 × aluguel` |
| CNPJ comercial | `tipoLocacao = "2"`; razão social, nascimento do responsável, capital social 450000, capital de abertura `100 × aluguel`, motivo comercial escolhido, renda `10 × aluguel` |
| CNPJ residencial | Mesmo enquadramento técnico PFCOM, capitais e renda de PJ; motivo "A" fixo no servidor, sem mostrar o seletor comercial |
| Locatários | Documento real, nome/razão social, nascimento DD/MM/AAAA, exatamente um titular S, e-mail/celular e dadosProfissionais somente com totalRendimentos. Contatos do cônjuge compartilhados; identidade e nascimento próprios. Consulta CPF já persistida, sem nova chamada por cotação |
| Contrato | Inícios na data da rodada; términos 30 meses depois; reajuste "002" IGP-M. Adotar como proposta técnica `tipoVigenciaContrato = "1"` (em vigor) para início no próprio dia e validar em homologação; não usar futura/indeterminada apenas porque aparecem no catálogo |
| Imobiliária | Origem cadastrada envia código Porto de seis dígitos vinculado ao corretor e usa FAT; demais origens usam CC. Lista dos códigos ainda aguardada; nenhum fallback novo definido |
| Coberturas | Tenant somente 001 aluguel; demais origens incluem 007–010 com IS igual ao aluguel e encargos 002–006 quando aplicáveis, positivos e até o aluguel. Aluguel 200–10000; não enviar cobertura zero |

Mantêm-se as confirmações do suporte sobre CPF de 11/CNPJ de 14 dígitos mesmo que o novo Markdown ainda mostre maxLength 11. Capital social fixo é regra definida para PJ, não motivo para preencher PF com dados empresariais fictícios. Não usar exemplos com capitais 500000, reajuste 001, renda null ou datas antigas como defaults. `diaVencimentoAluguel`, `orcamentoExterno`, nome social, CNPJ complementar e atividade econômica são opcionais conforme cenário; omitir quando não houver dado/regra aplicável. O retorno localRisco enriquece o endereço por CEP, mas não confirma número/complemento do imóvel. `regraFixa` é expressamente interno à Porto e pode ser ignorado.

### 3. GET: produto, condição e preço são um conjunto

O contrato confirma `ofertas[]` e `formasPagamento[]` como listas; **`formasPagamento[].condicoesPagamento` é objeto no GET**, enquanto o POST descreve lista de condições. A dúvida sobre a forma documentada está resolvida. Implementar normalização específica do GET; respostas reais divergentes precisam de evidência, sem aplicar silenciosamente o parser do POST.

Preservar, para cada alternativa, orçamento/cenário, `seguro`, `nomeSeguro`, `formaPagamento`, `nomeFormaPagamento`, `condicaoPagamento`, `quantidadeParcela`, `valorParcela`, primeiro vencimento e detalhes monetários. `condicaoPagamento` é identificador de três dígitos com zeros à esquerda; não usar "000" do exemplo como condição universal. Não deduplicar alternativas somente por forma de pagamento.

**Preço:** usar `valorParcela`, descrito como já incluindo IOF e descontos. Não dividir prêmio total, não somar IOF novamente, não confundir prêmio, IS, LMI ou LMG com parcela. Juros/adicional zero no exemplo ou no produto Essencial não autoriza apagar um valor real retornado. Quantidade e preço precisam ser utilizáveis; listas vazias ou comissão null no exemplo não significam recusa, parcela zero ou comissão zero.

**Prazo e entrada:** preservar a confirmação do usuário de 30 meses gerando 30 parcelas para CC/FAT. O GET descreve quantidade, valor, vencimento e código da condição, mas nenhum campo explícito `comEntrada`/`tipoEntrada`. Portanto, a opção desejada Sem entrada está decidida, porém sua identificação/seleção continua pendente. Não reduzir a 29 por analogia com Too e não selecionar quantidade diferente só por ter parcela menor.

**Produtos — atualização de 02/11/2026:** o usuário passou a adotar explicitamente o domínio documentado no PUT: **0001 Essencial 12; 0005 Essencial 30; 0007 Essencial 20; 0008 Fiança**. Isso supera a orientação histórica de descartar a associação 0008/Fiança. O GET continua sendo a fonte das ofertas realmente disponíveis e de seus preços: o catálogo não autoriza fabricar um produto ausente. Preservar nomes/códigos recebidos; a divergência do exemplo GET (0008 com nome ESSENCIAL 12) deve ser verificada no retorno real, sem reclassificação silenciosa. Fiança é o produto escolhido para comparação entre seguradoras; os Essenciais permanecem alternativas no card. Não presumir um quinto produto ou novo código para a denominação anterior “Tradicional”.

Coberturas, benefícios, agravos, descontos, cláusulas, acordo e cálculo permanecem ligados à oferta. Múltiplos LMI 12/20/30 não definem parcelas nem tornam os produtos equivalentes. Os limites mínimo/máximo de comissão e acordo não informam a comissão aplicada. O exemplo do GET mostra objetos null e listas vazias; o parser deve tratá-los sem produzir oferta comparável artificialmente.

### 4. PUT: comissão e limites desta etapa

**Comissão documentada:** `ofertas[].comissao.codComissao` usa pontos percentuais inteiros; enviar **15**, não 0.15. O padrão descrito pela Porto é 10%. Não calcular localmente a diferença de prêmio para simular 15% nem considerar limites retornados como prova da aplicação. Consultar novamente as ofertas depois do ajuste confirmado para usar o preço vigente. A existência de `calculo` no retorno do PUT indica dados calculados, mas não explica o recálculo de todas as ofertas.

**Esclarecimento do suporte recebido em 04/11/2026:** conforme o arquivo `Dúvidas-a-respeito-da-atualizacao-proposta.txt`, a API aceita o PUT do orçamento **somente com a comissão nas ofertas desejadas**, sem informar os demais campos. Os demais required do contrato completo são exigidos para emissão da proposta, fora desta versão do projeto. Essa resposta substitui a interpretação de 02/11 de que o ajuste de comissão exigiria o payload completo.

**Payload mínimo previsto para cada oferta**, usando o código efetivamente retornado na criação; 0008 abaixo é apenas exemplo:

```json
{
  "ofertas": [
    {
      "seguro": "0008",
      "comissao": { "codComissao": 15 }
    }
  ]
}
```

Omitir `seguro` na raiz, `cobranca`, `locador`, `localRisco`, `locatarios` e `locacaoComercial` neste PUT exclusivo de comissão. Não enviar objetos vazios/null nem copiar dados do locatário para fabricar um locador. A exigência anterior de igualdade entre seguro da raiz e do item deixa de se aplicar ao payload mínimo; `ofertas[].seguro` continua identificando a oferta ajustada. Manter path `orcamento`/`susep` e autenticação; a dispensa se refere aos demais campos do body, não aos requisitos de transporte.

### 4.1. Pendências encerradas e limites da confirmação

- **Resolvido:** possibilidade de alterar comissão sem preencher os dados exigidos para emissão.
- **Fora do escopo atual:** escolher locador na conjunta, obter endereçoPrincipal/enderecoAtual e telefone comercial PJ para completar o PUT. Não ampliar formulários nem bloquear o ajuste por ausência desses complementos.
- **Regra anterior superada para esta operação:** reutilizar dados dos locatários em locador. Uma eventual emissão futura exigirá estudo próprio dos dados reais e dos requisitos aplicáveis.
- **Preservado:** POST de criação com seus próprios requisitos, comissão inteira 15, seleção de pagamento FAT/CC conforme origem e consulta posterior do preço real.
- **Preservado:** uma chamada por oferta, simultâneas, conforme decisão anterior. O suporte mencionar “ofertas desejadas” não altera automaticamente essa decisão para um único PUT com todas as ofertas.
- **Validação técnica pendente:** executar o payload mínimo em homologação, conferir aplicação dos 15%, isolamento entre ofertas e atualização dos valores retornados. A resposta do suporte é confirmação contratual, não teste executado pelo projeto.

### 4.2. Aplicação por oferta e efeitos a validar

Criar orçamento → preparar um PUT mínimo por oferta retornada na criação, somente com ofertas[].seguro e comissão 15 → executar os PUTs simultaneamente → aguardar suas conclusões → consultar ofertas atualizadas para comparação. **Confirmação do usuário: realizar uma chamada de atualização para cada oferta disponível no orçamento, com execução concorrente, sem esperar uma terminar para iniciar outra.** Uma oferta resulta em um PUT; duas em dois; três em três; quatro em quatro. A quantidade vem das ofertas efetivamente retornadas pelo POST, não de uma lista fixa nem da quantidade de condições de pagamento. Não reunir produtos diferentes no mesmo PUT nem criar novo orçamento/análise por oferta apenas para ajustar comissão. Essa regra vale separadamente para cada orçamento/cenário aprovado; atualizar os Essenciais não os inclui automaticamente na comparação entre seguradoras. A orientação anterior de execução sequencial foi substituída por esta decisão.

**Isolamento local obrigatório:** preparar payload independente e imutável para cada oferta, com o código em ofertas[0].seguro, sem variável compartilhada que possa trocar o produto entre chamadas. Correlacionar cada resultado por rodada/attempt_id, cotação/orçamento e código da oferta, independentemente da ordem de conclusão. Persistir resultados por oferta sem sobrescrever o resultado de outra; proteger repetições da mesma operação por oferta, sem lock global que serialize todos os PUTs do orçamento. A futura implementação deve evitar que gravações concorrentes de um JSON agregado percam resultados. Concorrência não exige que as respostas cheguem no mesmo instante.

**Justificativa da concorrência — confirmada pelo usuário em 02/11/2026:** as atualizações de comissão são uma etapa interna do fluxo Porto. Esperar uma atualização terminar para iniciar a seguinte acumularia latência e atrasaria a entrega sem benefício de apresentação, pois o usuário não acompanhará cada recálculo de oferta. Executar as chamadas simultaneamente para reduzir o tempo total e apresentar apenas o orçamento final com comissão de 15% recalculada. Não exibir preços intermediários calculados com 10%, nem publicar atualização visual por PUT. A expressão “não rodar em fila” significa não serializar as atualizações de ofertas; **não significa remover os jobs, a fila de processamento ou o pacote de análises da arquitetura existente**. A coordenação continua interna e deve aguardar a conclusão das operações antes de disponibilizar os preços finais.

**Conclusão do grupo:** aguardar todas as atualizações disparadas antes do GET final e da comparação, evitando consolidar um catálogo parcialmente recalculado. Preservar sucesso/erro individual; uma falha não pode apagar os sucessos nem transformar aprovação em recusa. Não publicar oferta cujo ajuste de comissão não foi confirmado como se estivesse em 15%; efeitos desconhecidos exigem reconciliação com o orçamento existente.

**Concorrência remota ainda precisa de homologação:** todos os PUTs usam o mesmo recurso `/orcamentos/{orcamento}/{susep}` e passarão a enviar somente `ofertas`, sem campos de seleção na raiz. A documentação não garante isolamento por produto, ausência de sobrescrita entre PUTs concorrentes ou preservação dos PDFs. O requisito de simultaneidade está definido, mas não constitui garantia do comportamento da Porto. Validar que as comissões/condições de todas as ofertas permanecem corretas após execução concorrente e que o GET/PDF corresponde ao produto selecionado. Se a API substituir o estado de uma oferta pela outra, registrar a incompatibilidade e definir a solução com o usuário/suporte; não mudar silenciosamente para execução sequencial ou novas cotações.

O exemplo anterior com `codComissao = 10` não muda a regra de 15%. O desconto apresentado usa id 2, tipoAplicacao 1, minimo 0, maximo 1 e valorInformado 0; não representa desconto de 1% e não autoriza aplicar desconto adicional. Mantém-se o tratamento de descontos opcionais descrito abaixo.

Descontos opcionais têm IDs 2 negociação, 3 técnico promocional, 5 acordo; tipoAplicacao 1 percentual/2 valor; o documento sugere minimo 0, maximo 1 e valorInformado 0. A regra do projeto é adicionais/descontos configuráveis futuramente, atualmente zero; isso não obriga enviar todos os objetos opcionais. Omitir descontos não solicitados e preservar os descontos que a própria API retornar.

**Entrada não é dia de vencimento:** `diaVencimento` de FAT com/sem entrada é preenchido pelo cadastro da imobiliária e não pode ser alterado. Para Aluguel Garantido o campo é editável nas opções 5/10/15/20/25/30, produto/forma que não foi escolhido para substituir FAT/CC. `diaVencimentoAluguel` é outro dado; `diaCorte` é limite de processamento. Nenhum deles define sozinho Com/Sem entrada.

`cartaoCredito` é descrito como obrigatório **para emissão** em CP/CC. Esta etapa não deve coletar cartão nem chamar criptografia, autorização de pagamento ou emissão. As regras de cartão de teste e prêmio par do ambiente CP dizem respeito a essa modalidade, não à consulta de preço CC. O exemplo de PUT retornar status "4" (emitida) não comprova que todo PUT emita, mas também não comprova que uma atualização mínima de comissão seja livre de transição/efeito de cobrança: confirmar a operação no estado de orçamento.

### 4.3. Consulta do orçamento atualizado como alternativa — 02/11/2026

Fonte lida integralmente: [retorna-os-dados-atualizados.md](Porto_endpoitsMarkdown/retorna-os-dados-atualizados.md). Operação **`GET /orcamentos/{orcamento}/{susep}`**, mesma base de negócio, sem body e sem `origem` no caminho. Parâmetros: orçamento de 15 dígitos e SUSEP de seis caracteres; preservar zeros. Manter os dois headers de token já definidos pelo usuário. HTTP 200 retorna `CanonicoOrcamento`; isso confirma consulta, não novo orçamento, emissão ou aplicação da comissão por si só.

**Estratégia definida pelo usuário:** nos testes, verificar primeiro se o GET `/orcamentos/{origem}/{orcamento}/{susep}/ofertas` reflete os preços recalculados com 15%. Se não refletir, utilizar este GET do orçamento atualizado como fonte alternativa. Essa escolha deve ser fundamentada na homologação; não trocar de fonte arbitrariamente porque outra mostra preço menor ou porque houve falha de autenticação/transporte. Se nenhuma fornecer preço comprovadamente atualizado, não apresentar preço antigo como recalculado.

| Estrutura do novo retorno | Aplicação |
| --- | --- |
| `ofertas[]` | Produtos com seguro/nomeSeguro, coberturas, benefícios, cálculo e formasPagamento; procurar o produto pelo código, nunca pela posição |
| `ofertas[].formasPagamento[]` | Lista de formas por oferta; cada uma contém **condicoesPagamento como array** |
| `ofertas[].formasPagamento[].condicoesPagamento[]` | Fonte por produto de valorParcela, quantidadeParcela, condicaoPagamento, primeiroVencimento, IOF, descontos, prêmios e adicionais; selecionar FAT/CC, prazo e entrada conforme regras |
| Raiz `seguro`, `nomeSeguro`, `cobranca` | Produto e forma/condição selecionados no estado corrente do orçamento; não atribuir esse preço automaticamente a todos os produtos |
| Raiz `formasPagamento` | **Objeto**, com condicoesPagamento em array; diferente da lista de formas dentro de ofertas. Usar somente associado ao seguro da raiz quando aplicável, sem replicar para outras ofertas |
| `contrato`, `localRisco`, `locatarios`, `parceiro`, `corretor` | Dados para conferir vigências, identidades, corretor e vínculo; não sobrescrever o snapshot da rodada com o retorno nem expor dados pessoais desnecessários nas views |
| `orcamento`, `proposta`, `origem`, `susep`, `status`, `tipoRecusa` | Preservar identidades distintas e estado remoto. tipoRecusa 1 genérica/2 especial não equivale a aprovação/recusa isoladamente |

**Normalização própria:** o GET de ofertas usa condição como objeto; esta consulta usa array de condições e oferece tanto uma forma na raiz quanto listas por oferta. Não reutilizar o parser sem distinguir essas estruturas. Valor de parcela vem diretamente da condição correspondente, sem dividir prêmio total; tratar dinheiro com precisão e preservar o bruto recebido para diagnóstico. Calculo da raiz não substitui valorParcela de cada produto.

**Limite da evidência:** o schema não declara `comissao.codComissao` aplicado, nem dentro de ofertas, nem na raiz. `cocorretagens.percentual` refere-se à cocorretagem e não deve ser interpretado como a comissão configurada pelo PUT. A palavra “atualizados” não comprova sozinha o percentual. Nos testes, correlacionar PUT com codComissao 15, sua resposta e a consulta posterior do mesmo orçamento/seguro/forma/condição; confirmar o efeito do ajuste com evidência do ambiente ou suporte, sem exigir uma variação arbitrária de preço nem calcular localmente a parcela.

**Concorrência preservada:** aguardar todos os PUTs por oferta terminarem antes das leituras finais. Comparar ambas as consultas com todas as ofertas atualizadas, verificar se cada produto conserva sua alteração e registrar qual endpoint originou cada preço. O novo GET não recebe filtro `seguro`; se retornar apenas o último produto selecionado ou ofertas não recalculadas, não consegue comprovar sozinho todos os preços após atualizações concorrentes. Nesse caso, registrar a incompatibilidade para resolver com o usuário/suporte, sem serializar silenciosamente os PUTs ou criar novas cotações.

**Respostas e inconsistências:** 400 usa `status`/`message` (incluindo orçamento não pertencente ao corretor), enquanto 500 usa errorCode/errorMessage; 401/502 podem ser strings. Falha de consulta não é recusa. O status de negócio 0/1/2/3/4/5/7/9/C/D/E/F/G/M é distinto do HTTP; campos apolice/renovacao/status 4 no exemplo não autorizam emissão. O exemplo contém ofertas vazias e formasPagamento null, logo não prova o fallback. Schema ainda possui integer para dinheiro, quantidadeParcela mínimo 100 incompatível com 30, CEP máximo 7, nomes/limites conflitantes e seguro 0008 com nome FIanca Essencial 12: manter regras confirmadas e validar respostas reais, sem copiar esses limites cegamente. `locatarios.cpfCnpj` com máximo 14 e tipoPessoa F/J reforçam o suporte já confirmado a CNPJ.

A consulta exige um orçamento conhecido, portanto **não resolve a obtenção do ID ausente no POST recusado**. Pode consultar recusa com ID já conhecido, preservando a pendência específica da carta. O contrato também não apresenta locador na resposta; sua ausência não impede o PUT exclusivo de comissão, conforme esclarecimento do suporte de 04/11/2026.

### 5. Fluxo proposto dentro da arquitetura existente

1. Coordenador prepara pacote/rodada/cenários; Porto terá uma criação por cenário, não quatro criações por produto. PF sem cônjuge: uma; PF com cônjuge: duas individuais e terceira conjunta somente se ambas forem recusadas. PJ: somente cenário empresarial primary, sem estado civil, cônjuge ou análise individual do responsável, conforme regra geral de 02/11/2026.
2. Builder consome snapshot e datas da rodada e monta POST mínimo. Service autentica/transmite; provider interpreta 201/409 e grava o ID remoto na cotação correta. Falha após criação não autoriza recriar orçamento.
3. Para aprovado, usar `ofertas[]` e suas formas/condições presentes na resposta do POST, sem GET preliminar no fluxo definido. Para cada oferta retornada, montar um PUT mínimo independente contendo somente ofertas, com um item identificado por seguro e comissao.codComissao = 15. Disparar as atualizações simultaneamente no mesmo orçamento, conforme decisão do usuário, com resultados isolados por oferta e proteção contra sobrescrita local. Se a resposta real do POST não fornecer códigos das ofertas suficientes, registrar a lacuna para homologação e revisar a integração com evidência; não inventar valores nem inserir silenciosamente outra etapa. Código de oferta ausente impede seu ajuste, sem transformar aprovação em recusa; complementos de emissão não são exigidos neste PUT. Não publicar preços calculados com padrão 10% como se fossem 15%.
4. Após todas as atualizações disparadas concluírem, consultar preços finais: preferir o GET de ofertas; se a homologação mostrar que não reflete o recálculo, usar GET do orçamento atualizado conforme seção 4.3. Selecionar forma por origem, 30 parcelas e Sem entrada quando identificável, considerando o sucesso do ajuste de cada oferta. Persistir resposta completa, endpoint de origem e seleção (produto + forma + condição + parcela) associadas à cotação, com o estado da aplicação da comissão. O catálogo inicial não deve sobrescrever preços finais.
5. `InsuranceQuotePayment` receberá tratamento Porto próprio. Hoje seu match só cobre Too/Pottencial, e o coordenador inicializa Invoice/Boleto: adaptar o pagamento para FAT/CC sem mudar as outras companhias. Manter uma cotação remota por cenário, preservando múltiplas ofertas nos dados da cotação; não alterar o `plan_key` de coordenação para cada produto retornado. A representação normalizada das ofertas será definida na implementação, sem prometer que a simples inclusão do provider já basta.
6. Coordenador consolida somente parcela elegível. Aprovação sem ofertas utilizáveis continua aprovação com pagamento indisponível/falho; não autoriza conjunta. Painel/e-mail/PDF devem apontar a mesma oferta/condição final. Downloads preservam os IDs de cada alternativa e são autorizados localmente.
7. Reanálise inicia outra rodada e novos POSTs; PUT não altera os pretendentes. O contrato proíbe incluir/excluir locatários no PUT e exige novo orçamento para isso. Consultas e retomadas usam IDs conhecidos; executar PUTs concorrentes por oferta e coordenar a leitura final após suas conclusões, respeitando attempt_id para evitar resultados antigos.

No service, diferenciar sucesso HTTP de resultado de negócio: GET/PUT 200 não significam aprovação cadastral. POST/PUT documentam 409 de recusa; 400, 401, 500 e 502 são falhas próprias da operação, não recusas genéricas. O PUT enumera status 0/1/2/3/4/5/7/9/C/D/E/F/G/M; preservar o status bruto, não tratar todos como aprovados e não aplicar a regra Tokio “diferente de CAL é recusa”. Esses estados não demonstram que o POST retorne encaminhamento. Não repetir POST/PUT cegamente após timeout: efeitos remotos precisam ser verificados. Segredos/cartão não devem integrar logs; payloads/resultados seguem a proteção e as permissões existentes.

### 6. Pendências consolidadas e verificações de homologação

| Ponto | O que ainda precisa ser esclarecido |
| --- | --- |
| **Dados do PUT — resolvido pelo suporte em 04/11/2026** | Enviar somente ofertas com seguro e comissão 15. Demais campos obrigatórios do contrato completo são para emissão, fora desta versão; mapeamento de locador/endereço/telefone para esse PUT deixa de ser pendência |
| **Fonte dos preços recalculados — validação** | Testar GET de ofertas após os PUTs com 15%; se não refletir, usar GET do orçamento atualizado. Confirmar atualização e isolamento por produto, pois o novo schema não expõe comissão aplicada e a consulta não filtra por seguro |
| **Atualizações simultâneas — homologação** | Simultaneidade definida pelo usuário, uma requisição por oferta, resultados locais isolados. Verificar se a Porto preserva comissão/condições de todas as ofertas nos PUTs concorrentes sobre o mesmo orçamento e mantém seus PDFs coerentes; não presumir isolamento remoto. Vários produtos na mesma requisição continuam descartados |
| **Sem entrada — prioridade** | Qual campo/código da condição identifica Sem entrada para CC e FAT, e basta selecionar a condição do GET ou é preciso gravá-la no PUT? O usuário já informou que aguarda essa confirmação |
| **Carta de recusa** | POST 409 continua documentado somente com errorCode/errorMessage; como recuperar o número de orçamento necessário ao PDF? Nos 409 de PUT já existe ID conhecido. Disponibilidade do PDF sem emissão permanece validação separada |
| **CNPJ e cônjuge — resolvido** | Regra geral de 02/11/2026: analisar somente a empresa pelo CNPJ; desconsiderar estado civil e cônjuge; não analisar o responsável nem criar spouse/joint. Aplicação no código ainda pendente |
| **Imobiliárias** | Lista de códigos internos ainda aguardada e validação do vínculo corretor/parceiro. A decisão FAT/CC está fechada |

**Verificações técnicas, sem reabrir decisões de negócio:** homologar 30 parcelas reais em CC/FAT; aceitar início no dia com vigência "1"; conferir omissão condicional de locacaoComercial/capitalSocial em PF; tipos monetários decimais apesar de integer; CPF/CNPJ conforme suporte; dados de endereço/DOB e formatos reais de documentos. O exemplo GET sem condições não basta para validar preço. O domínio 0008/Fiança foi reafirmado na correção mais recente; confrontar com a oferta real disponível e registrar divergências, sem trocar nomes silenciosamente nem gerar ofertas ausentes.

**Contradições documentais preservadas para diagnóstico:** POST introdução 200 versus responses 201; documento CPF/CNPJ ainda limitado a 11; reajuste máximo 1 versus códigos de três dígitos; e-mail máximo 1/30 versus descrição 64; dinheiro integer com exemplo decimal e mínimos genéricos incompatíveis com encargos; enum de cobrança CP/CC/FAT/ADC versus descrição adicional BFT/BCA/BOL; endereço obrigatório com exemplo null; dadosProfissionais obrigatório com exemplo null; limites de cartão inconsistentes; códigos/nomes de produto divergentes. Não truncar dados reais ou copiar placeholders para cumprir esses defeitos. As confirmações do usuário e os campos efetivos por operação permanecem separados de exemplos.

**Validação desta revisão:** leitura integral dos três arquivos, confronto de campos/operações e inspeção pontual do snapshot, coordenador, pagamento e model de locador. Somente este estudo foi editado; nenhuma chamada remota, teste de integração, migration, formulário ou código de execução foi alterado.

## Porto Seguro — autenticação — 30/10/2026

Fontes fornecidas pelo usuário: `C:\Users\Usuario\Downloads\request-obter-token-porto.txt` e `C:\Users\Usuario\Downloads\resposta-request-obter-token-porto.txt`. Esta seção registra o contrato apresentado; nenhuma chamada remota foi realizada. As referências anteriores à Porto neste estudo tratavam das regras gerais e da integração futura, sem detalhar sua autenticação.

### Obtenção do token

| Item | Contrato recebido |
| --- | --- |
| Ambiente | Homologação, conforme hostname `portoapi-hml.portoseguro.com.br` |
| Método e URL | `POST https://portoapi-hml.portoseguro.com.br/oauth/v2/access-token` |
| Content-Type | `application/x-www-form-urlencoded` |
| Authorization | `Basic <Base64(client_id:client_secret)>` |
| Corpo | `grant_type=client_credentials`, enviado como formulário, não JSON |

O cURL documental contém entidades HTML `&#039;` no lugar de aspas: decodificá-las ao preparar uma requisição executável. O trecho entre colchetes no Authorization é uma instrução de composição das credenciais, não um valor literal. Preferir o mecanismo de Basic Auth do cliente HTTP para montar esse header. Não colocar as credenciais na URL nem registrar o header em logs. O exemplo usa `--location`; isso não exige habilitar redirecionamentos no cliente da aplicação.

### Resposta apresentada

| Campo | Tipo observado | Interpretação |
| --- | --- | --- |
| `access_token` | string | Credencial retornada para as operações autenticadas; não reproduzir seu valor no estudo |
| `token_type` | string, `Bearer` | Usar `Authorization: Bearer <access_token>` nas operações que adotarem essa autenticação |
| `expires_in` | número, `3600` | Prazo informado de 3.600 segundos, equivalente a uma hora |

A resposta fornecida contém apenas o corpo JSON: não comprova status HTTP, headers ou erros. Não contém `refresh_token`, `scope` ou campos de identificação da cotação. Não inventar esses atributos nem assumir outros headers das operações de negócio antes de receber seus contratos.

### Diretrizes para a implementação futura

- Centralizar autenticação no service da Porto, reutilizado pelo provider; não consultar token no builder nem alterar a coordenação de rodadas/cenários.
- Configurar URL e credenciais por ambiente em `config/services.php`, com segredos no ambiente. Não presumir a URL de produção a partir da URL de homologação.
- Validar sucesso HTTP e presença de token não vazio, tipo Bearer e validade positiva antes de disponibilizar a autenticação ao consumidor.
- Reutilizar o token até próximo da expiração, calculando o cache pelo `expires_in` retornado, com margem de segurança. Isolar o cache por ambiente/credencial e proteger a renovação concorrente, pois vários jobs poderão usar a Porto simultaneamente.
- Na expiração, obter outro token pelo mesmo fluxo `client_credentials`; o material não documenta renovação por refresh token.
- Falha de autenticação é falha técnica, não recusa de crédito. Não repetir automaticamente uma criação de cotação após timeout ou efeito remoto desconhecido apenas porque um token foi renovado.
- Manter tokens e credenciais fora de logs, payloads de cotação, eventos e respostas destinadas às views. A autenticação não implica emissão ou contratação.

### Pendências documentais

Os contratos de orçamento, ofertas e PDFs foram recebidos na revisão abaixo. Permanecem pendentes respostas de erro específicas da obtenção de token, política de limites e dados do ambiente de produção. O valor `3600` é o retorno apresentado, não uma garantia de validade fixa para todos os tokens. O fluxo está estudado, mas ainda não implementado nem homologado no sistema.

**Confirmação posterior do usuário:** nas operações de negócio, enviar o mesmo token nos dois headers: `Authorization: Bearer <token>` e `access_token: <token>`. O Swagger declara somente Authorization; o erro 401 avulso menciona access_token. A confirmação resolve a decisão de implementação sem apagar essa divergência documental. A obtenção de token continua usando Basic Auth conforme o contrato próprio.

## Porto Seguro — orçamento, ofertas e documentos — revisão de 30/10/2026

### Fontes e alcance da análise

Confrontados os oito arquivos enviados em Downloads: `request_calculo_orcamento.txt`, `resposta200-calculo-orcamento.txt`, `resposta200-calculo-orcamento-Schema.txt`, `especificacoes-de-campos-porto-request-calculo-orcamento.txt`, `request-calculo-orcamento-url.txt`, `error-400-porto.txt`, `error-code-401-porto.txt` e `error-code-500.txt`; a imagem do 409; as confirmações do usuário; e, posteriormente, `C:\Users\Usuario\Downloads\especificacoes-api-porto.txt`.

O último arquivo é um documento JSON Swagger **2.0**, título **Riscos Financeiros - Aluguel Essencial**, versão **1.0.0**, contendo **31 caminhos, 33 operações e 69 definições**. A leitura estrutural validou o JSON e resolveu as **232 referências locais**, sem referências ausentes. Isso não comprova conformidade semântica do Swagger nem funcionamento remoto: há contradições de tipos, obrigatoriedade e limites. As definições `beneficios` e `Beneficios` são distintas por caixa; ferramentas que ignoram essa distinção não conseguem importar o documento corretamente. Não modificar a fonte para contornar essa limitação.

Host de negócio: `https://portoapicloud-hml.portoseguro.com.br`. Prefixo: `/fianca-locaticia/emissao/aluguel-essencial/v1`. É diferente do host de autenticação recebido anteriormente; não substituir um pelo outro por inferência. Todos os caminhos abaixo são relativos a esse prefixo. O termo `emissao` no prefixo não significa que criar orçamento emita uma apólice.

### Endpoints relevantes ao escopo de análise

| Operação | Contrato e uso |
| --- | --- |
| `POST /orcamentos` | Criar orçamento/analisar; JSON; resposta de sucesso declarada 201, recusa 409 |
| `GET /orcamentos/{origem}/{orcamento}/{susep}/ofertas` | Consultar ofertas e condições; 200; será a fonte de preço escolhida pelo usuário |
| `GET /orcamentos/{orcamento}/{susep}` | Consultar dados atuais; operação disponível para diagnóstico/recuperação com identificador conhecido, sem impor polling ao fluxo síncrono |
| `PUT /orcamentos/{orcamento}/{susep}` | Atualizar orçamento; documenta ajuste de comissão por oferta; exige esclarecimentos antes de incluir no fluxo |
| `GET /orcamentos/{proposta}/{corretor}/pdf/carta-recusa` | Carta exclusivamente para orçamento recusado; 200; proposta significa número do orçamento, corretor significa código interno SUSEP |
| `GET /orcamentos/{orcamento}/{susep}/pdf/cotacao` | PDF geral do orçamento; 200; descrito como disponível para propostas em digitação |
| `GET /orcamentos/{orcamento}/{susep}/pdf/cotacao/{seguro}/{formaPagamento}/{condicaoPagamento}` | PDF da oferta e condição específicas; 200; permite manter coerência com a parcela selecionada |
| `GET /orcamentos/{orcamento}/{susep}/pdf/carta-aprovacao` e variante `/{seguro}` | Cartas de aprovação; não confundir com PDF de cotação |
| `POST /orcamentos/{origem}/{proposta}/{corretor}/pdf/carta-orcamento` | PDF filtrado por listas de ofertas e formas de pagamento; resposta declarada 201 |
| `POST /orcamentos/{origem}/{orcamento}/{susep}/pdf/simulacao` | PDF de simulação por ofertas, formas, quantidades e descontos; resposta declarada 201; não confundir com consulta de ofertas/preço |

`origem` é domínio da Porto: **22 = emissão/nova contratação; 40 = renovação**, e não a origem do formulário local. Para o fluxo de nova cotação, 22 é o valor documental correspondente; não usar 40 nas reanálises, que continuam sendo novas solicitações. `orcamento` tem 15 dígitos, `susep` tem seis caracteres e termina em F ou J. Preservar todos os identificadores como texto e zeros à esquerda.

O inventário também contém atualização/recalculo, edição, complementares, reenvio de biometria, emissão PUT/PATCH de propostas (incluindo uma rota depreciada), consulta de apólices/endossos, boletos, aditivo, criptografia de cartão, busca de documentos, cancelamento e cadastro de webhook. Essas operações não são necessárias apenas por constarem no arquivo; emissão, cobrança, biometria, cancelamento e webhook ficam fora desta etapa. Não usar recalculo como substituto da regra geral de reanálise por novo POST.

### Aprovação, recusa e falhas

Prevalece a confirmação do usuário, respaldada por `responses` do POST: **HTTP 201 = aprovação; HTTP 409 = recusa de aceitação**. A descrição introdutória menciona 200 e os arquivos de exemplo têm 200 no nome, mas isso não altera a regra. `errorCode` do corpo não substitui o status HTTP e não identifica um orçamento.

400 indica validação/inconsistência de dados ou regra de negócio; 401 indica autenticação; 500/502 indicam falha técnica/indisponibilidade. Não converter esses casos em recusa nem autorizar a tentativa conjunta por falha técnica. O erro 401 avulso é texto, portanto o cliente não pode presumir JSON em todas as falhas.

O schema 409 `ProtheusRecusaAceitacao` contém somente `errorCode` e `errorMessage`: **a origem do número necessário à carta de recusa continua sem solução documental**. Não extrair um ID inventado da mensagem nem usar o código 409 como proposta. A ausência de documento não deve apagar uma recusa conhecida.

Outras operações descrevem estados internos como orçamento criado/aprovado, pré-aprovado, proposta em digitação, recusada e análise de crédito/emissão. Esses estados pertencem a outras etapas; sua presença não comprova um retorno pendente no POST de criação nem revoga 201/409. Se uma resposta real divergir, preservar evidência antes de ampliar o mapeamento.

### Payload de criação: diferenças em relação aos exemplos iniciais

| Campo | Evidência e aplicação |
| --- | --- |
| `susep` | Obrigatório, código interno do corretor, seis letras/dígitos terminando em F/J; não é o CPF do corretor |
| `cpfCotador` | Obrigatório; operador definido pelo usuário como o corretor. Fonte local identificada: `CPF_COTADOR` no .env; não usar CPF do locatário |
| `orcamentoExterno` | Opcional; limite 15 no POST versus 32 na descrição e no PUT. Não equivale a uma chave de idempotência documentada |
| `imobiliaria` | Código Porto de seis dígitos, opcional no contrato; disponibiliza FAT quando o parceiro possui vínculo com o corretor |
| `cep` | Obrigatório, oito dígitos; endereço retornado é enriquecido pela base de CEP; não tratar o número/complemento ilustrativo como dado verificado do imóvel |
| `diaVencimentoAluguel` | Texto de dois dígitos, fora do required do POST; não confundir com dia de faturamento ou `diaCorte` retornado |
| `tipoLocacao` | Obrigatório: **"1" = PFRES; "2" = PFCOM**. Resolve documentalmente a ausência de finalidade no recorte anterior |
| `locatarios[]` | Agora exige CPF, **nome, dataNascimento, titular, email, telefoneCelular e dadosProfissionais** |
| `contrato` | Exige vigências e **codigoReajuste**, ausente no exemplo inicial |
| `locacaoComercial` | Descrição indica obrigatoriedade apenas em PJ/PFCOM, reiterada no detalhamento posterior. Para PFRES, o desenho segue a omissão do objeto; o required incondicional permanece como divergência a validar na integração |
| `locador` | Está em required da raiz do POST, mas não há propriedade/schema correspondente nessa operação. Existir uma definição Locador para outras operações não resolve automaticamente esse defeito |

`OrcamentoLocatario` descreve CPF de 11 dígitos; exatamente um titular S e demais N. Nome: 5–60 caracteres, nome e sobrenome. Nascimento: `DD/MM/AAAA`; usar data própria persistida da API CPF Brasil ou da entrada manual autorizada pela feature flag, congelada na rodada. Não consultar CPF de novo em cada job. E-mail e celular são obrigatórios por locatário; ainda falta definir a origem do contato do cônjuge, sem presumir que seja compartilhado.

`dadosProfissionais` é obrigatório para PFRES e PFCOM; internamente somente **totalRendimentos** consta como obrigatório. Empresa, vínculo empregatício, expatriado e admissão não devem ganhar valores fictícios. `totalRendimentos` é declarado integer, mas seu exemplo contém centavos; registrar conflito. A regra geral de renda de quatro aluguéis é candidata ao mapeamento, mas confirmar que este campo representa renda mensal antes de equipará-los.

**Elegibilidade — atualizada pelo suporte em 31/10/2026:** CPF e CNPJ estão confirmados pelo usuário após resposta da Porto. A limitação a 11 dígitos do Swagger recebido está superada por essa confirmação; aplicar a matriz PF/PJ da seção de 31/10 abaixo. Isso resolve a regra contratual, sem equivaler a homologação remota do payload final.

**Comercial PF:** LocacaoComercial descreve `capitalAberturaNegocio` como obrigatório para PFCOM; `cnpj` e `atividadeEconomica` são opcionais nesse cenário. Atividade usa "1" Serviço, "2" Indústria, "3" Comércio. `capitalSocial` e `motivoLocacao` são descritos como obrigatórios para PJ, embora o required do objeto exija os três campos para todos. Não inventar capital, CNPJ ou motivo. Os códigos de atividade são específicos Porto, não CNAE nem domínio Tokio.

**Contrato:** preservar início na data da análise e término 30 meses depois, conforme rodada, em `DD/MM/AAAA`. Tipo de vigência é "0" futura, "1" em vigor, "2" indeterminada; início no mesmo dia sugere "1", mas sua aceitação exata deve ser confirmada. `codigoReajuste` tem domínio 001 Maior índice; **002 IGP-M**; 003 IGP-DI; 004 IPC/FIPE; 005 IPCA; 006 INPC; 007 ICV; 008 INCC; 009 IPC/FGV; 010 IVAR. O schema limita o campo a um caractere apesar de descrever três. A decisão IGP_M da Too não autoriza automaticamente escolher 002 na Porto; falta confirmar o índice desta companhia.

### Coberturas e vínculo com imobiliárias

Tenant envia somente `001` Aluguel. Nas demais origens, adicionar `007` danos, `008` pintura interna, `009` externa e `010` multa, cada uma com `valorIS` igual ao aluguel, além dos encargos aplicáveis: `002` IPTU, `003` condomínio, `004` água, `005` luz e `006` gás. Pelo texto: aluguel entre 200 e 10000; encargos opcionais entre 0,01 e o aluguel. Omitir coberturas não desejadas, nunca enviar zero. Não confundir importância segurada, LMI, LMG e preço; não multiplicar o valor mensal por 30 sem regra contratual para esse campo.

O usuário confirmou que **todas as imobiliárias atuais do cliente possuem código interno Porto** e enviará a lista. Associar ao cadastro local por um processo futuro; não usar CNPJ, ID local ou código Tokio como código Porto. Para a origem cadastrada, planejar envio do código e seleção FAT, respeitando o vínculo corretor/parceiro. Não foi definido fallback para cadastro futuro sem código. Para demais formulários, manter a regra geral de crédito, usando a condição retornada compatível (`CC` para demais cartões, sem presumir cartão Porto `CP`). A existência de BOL no catálogo não autoriza transportar a exceção boleto de Too/Pottencial para Porto.

### Ofertas, comissão e comparação

O GET de ofertas resolve `Ofertas`, diferente de `Oferta` retornada na criação. **Correção do usuário em 31/10/2026:** não usar o exemplo de criação como catálogo fixo de produtos; a associação `0008 = Fiança` foi descartada. Para identificar e exibir ofertas, usar os pares `seguro`/`nomeSeguro` efetivamente retornados pelo GET de ofertas. O nome Essencial 30 não comprova quantidade de parcelas nem equivalência de coberturas. As regras de comparação e prazo estão nas confirmações posteriores.

Cada oferta possui comissão mínima/máxima, formas de pagamento, coberturas, benefícios, agravos, descontos, cláusulas, acordo e cálculo. Preservar os códigos de oferta, forma e condição da mesma alternativa. O GET referencia `formasPagamento[].condicoesPagamento` como **objeto**, enquanto a resposta do POST usa **array** de condições. Confirmar formato real; não reutilizar o parser do POST às cegas.

`valorParcela` é explicitamente a parcela com IOF e descontos. Usar diretamente esse valor na condição de 30 parcelas definida pelo sistema, sem dividir prêmio total e sem somar IOF novamente. O schema contém limites diferentes entre operações e exemplos de quantidades menores; não conclui disponibilidade de 30x para todas as ofertas/formas. O parâmetro de simulação de PDF aceita até 60, o que também não garante oferta de pagamento. Ausência de preço elegível não desfaz aprovação.

**Comissão de 15%: campo localizado.** O PUT de atualização documenta `ofertas[].comissao.codComissao`, inteiro em pontos percentuais: o valor de negócio corresponde a **15**, não 0.15. A descrição informa default da Porto em **10%**; não assumir que o POST já calcule com 15%. O GET informa `comissao.percentualMinimo` e `percentualMaximo` por oferta. O texto do PUT menciona aceitar ofertas no POST, mas esse objeto não aparece no schema de criação; também avisa que filtra as ofertas exibidas.

Para fechar o fluxo de 15%, confirmar se o POST aceita esse objeto ou se será necessário POST → PUT de comissão → GET de ofertas, se a alteração recalcula os valores e quais campos mínimos o PUT realmente exige. O required do PUT inclui cobrança, locador, imóvel, locatários e locacaoComercial; não enviar PUT incompleto nem chamar emissão/recalculo por suposição. O campo foi encontrado, mas a aplicação da comissão à parcela comparada **não está validada**. Não usar preço do PDF de simulação com descontos como substituto silencioso do preço do orçamento.

A condição Sem entrada permanece como regra do sistema. O PUT descreve FAT com e sem entrada e vencimento controlado pelo cadastro da imobiliária, mas os schemas de condições não trazem um discriminador claro para selecionar Sem entrada. Receber retorno real/catálogo para identificar a condição; não transportar `tipoEntrada = 2` da Too ou interpretar todo primeiro vencimento como entrada.

### PDFs e cartas: formato esclarecido

As rotas de cotação e recusa retornam JSON `SucessoGeracaoPDF`: `status`, `message` e **data.file**, contendo o PDF em **Base64**. Esse envelope é diferente de `result.pdfCotacao` da Too. Validar HTTP, envelope e Base64, verificar conteúdo PDF e servir bytes com autorização local. O maxLength 500 do schema não é um limite plausível a impor ao arquivo; não truncar documentos.

O GET de PDF específico permite escolher `seguro`, `formaPagamento` e `condicaoPagamento`, preservando a mesma oferta/preço apresentado no painel. O POST carta-orcamento aceita listas de ofertas e formas; o POST simulacao recebe descontos e ofertas com forma/quantidade, sem equivaler a emitir apólice. Ambos declaram 201 em responses e 200 no texto introdutório: confirmar em homologação por operação. A condição "proposta em digitação" nos GETs de cotação exige verificar disponibilidade após criar orçamento, sem emitir para obter PDF.

### Arquitetura e pendências para fechar o estudo

Reutilizar o pacote, rodada, snapshot, cenários primary/spouse/joint, IDs e eventos por cotação. Uma criação Porto retorna ofertas; isso não demanda uma criação por oferta como os planos Pottencial. Em cada cenário individual, seu único pretendente é titular S; na conjunta, um S e outro N. A conjunta continua condicionada às duas recusas, não a erro HTTP, ausência de parcela ou falha de PDF. A comissão, ofertas e PDFs devem permanecer associados ao orçamento/cenário correto. Não repetir POST depois de timeout com efeito remoto desconhecido; orcamentoExterno não tem garantia documentada de deduplicação.

Pendências prioritárias, sem repetir perguntas já resolvidas:

1. Confirmar com a Porto a exigência de locador sem schema no POST e validar as condições de locacaoComercial contra o required incondicional. A obrigatoriedade documental de cpfCotador está confirmada; falta definir o CPF do operador por configuração, sem confundi-lo com o pretendente. Seguir a matriz condicional do detalhamento abaixo, sem fabricar campos PJ para PF.
2. Confirmar periodicidade de totalRendimentos e tipo de vigência para início no dia. Capital de abertura, índice e contatos do cônjuge foram definidos na atualização abaixo. Não acrescentar campos ao formulário nesta fase de estudo.
3. Confirmar aplicação dos 15% antes da consulta de preços, body mínimo da operação necessária, ofertas elegíveis, condição de 30 parcelas Sem entrada e formato objeto/array no GET.
4. Obter de uma recusa real o identificador exigido na carta (ou orientação oficial de recuperação); obter PDFs reais para confirmar disponibilidade após o orçamento.
5. Receber a lista de códigos das imobiliárias. O suporte confirmou PF/PJ e o uso de PFCOM para CNPJ em 31/10/2026; permanecem os detalhes de preenchimento PJ descritos na atualização dessa data.

**Verificação desta revisão:** leitura do contrato completo por parsing e inspeção direcionada das operações/definições, comparação com os exemplos anteriores e com decisões do usuário. Somente este estudo foi atualizado; sem chamadas externas, alteração de código, testes de integração ou homologação. A aparente simplicidade do fluxo não elimina os campos adicionais e as contradições identificadas.

### Complemento — especificações detalhadas do request — 30/10/2026

Fonte lida integralmente: `C:\Users\Usuario\Downloads\especificacoes-satisfatorias-porto.txt`. Trata-se de texto extraído da documentação dos campos do request, não uma resposta real nem um novo endpoint. Confrontado com o Swagger completo e com o primeiro recorte de campos. Este complemento refina as pendências anteriores sem apagar as inconsistências das fontes.

**Confirmações documentais:**

- `cpfCotador` aparece obrigatório tanto na marcação quanto na descrição. Encerra a dúvida documental de opcionalidade do primeiro recorte; continua pendente apenas a fonte do CPF do operador no sistema.
- `tipoLocacao` é obrigatório: "1" PFRES e "2" PFCOM. A enumeração mantém o escopo CPF; referências PJ em objetos compartilhados não acrescentam um código PJ ao endpoint.
- Para cada locatário: CPF de 11 dígitos, nome de 5–60 caracteres com espaço entre nome e sobrenome, nascimento `DD/MM/AAAA`, titular S/N, e-mail, celular de 11 dígitos e dadosProfissionais obrigatórios. Nome social é opcional. Exatamente um titular por orçamento; individuais terão seu único locatário como S, conjunta terá um S e um N.
- `dadosProfissionais` é obrigatório em PFRES e PFCOM, mas o novo recorte não expande suas cinco propriedades: mostra somente o resumo `{ empresa, vinculoEmpregaticio, expatriado, +2 }`. Portanto, permanece como fonte dos campos internos o Swagger, que exige apenas totalRendimentos. O recorte não define a periodicidade da renda nem autoriza copiar quatro aluguéis para capital de abertura.
- `contrato.codigoReajuste` é obrigatório; seus códigos são strings de três caracteres, inclusive "002" para IGP-M. A escolha de IGP-M para Porto ainda é uma decisão pendente, não resolvida pela simples existência desse código.
- `imobiliaria`, `orcamentoExterno`, `diaVencimentoAluguel` e nomeSocial não aparecem obrigatórios. A regra de enviar imobiliaria para a origem cadastrada decorre da elegibilidade de FAT e das decisões do sistema, não de obrigatoriedade universal da API.

**Matriz condicional segundo as descrições, reforçada pelo novo material:**

| Cenário | locacaoComercial | Campos comerciais |
| --- | --- | --- |
| PF residencial | Não aplicável; planejar omissão | Não preencher capital ou motivo apenas para satisfazer required genérico |
| PF comercial | Obrigatório | capitalAberturaNegocio obrigatório; cnpj e atividadeEconomica opcionais |
| PJ, confirmado posteriormente em 31/10 | Obrigatório no enquadramento PFCOM | capitalSocial e motivoLocacao descritos como obrigatórios; residencial PJ usa motivo A, conforme confirmação posterior |

O rótulo de obrigatório em todos os campos conflita com as condições textuais. A regra de negócio descrita está mais clara: **não tratar capitalSocial e motivoLocacao como obrigatórios do PFCOM**, nem locacaoComercial como informação real de residencial. A aceitação da omissão deve ser verificada em homologação; eventual rejeição deve ser levada ao suporte, não contornada enviando dados fictícios. Capital social e capital de abertura possuem faixa declarada 0–99999999, mas zero permitido não significa valor padrão aprovado para quem não informou capital.

`motivoLocacao` é específico da descrição PJ: 1 Abertura de Matriz; 2 Cedido; 3 Redução de Custos; 4 Venda do imóvel atual; 5 Troca de local de sede; 6 Locação adicional; 7 Abertura de filial; 8 Troca de garantia; 9 Abertura de nova empresa; A Locação para moradia. Não escolher automaticamente uma opção. `atividadeEconomica` mantém 1 Serviço, 2 Indústria, 3 Comércio, opcional em PFCOM; eventual mapeamento deve partir do ramo real selecionado, não do código de atividade Tokio.

**Coberturas:** o texto reitera aluguel 001 obrigatório, mínimo 200 e máximo 10000; encargos 002–006 opcionais, de 0,01 até o aluguel; adicionais 007–010 iguais ao aluguel. Cobertura não escolhida é omitida e não aceita valor menor ou igual a zero. Isso sustenta a regra tenant somente aluguel e os quatro adicionais nas demais origens, preservando centavos. O tipo integer/int32 e mínimo genérico 100 de valorIS contradizem a descrição e não justificam truncar centavos ou rejeitar um encargo documentado de 0,01.

**O que não foi solucionado:**

- `locador` continua ausente da listagem, embora conste no required do Swagger. A omissão no novo recorte reforça a inconsistência, mas não prova dispensa no servidor.
- Persistem os limites incompatíveis: e-mail máximo 1 versus descrição de 64, codigoReajuste máximo 1 versus códigos de três dígitos, orcamentoExterno máximo 15 versus descrição de 32, além do formato inteiro de valores monetários decimais. O texto de e-mail também exige um ponto após @ e ao mesmo tempo restringe os caracteres especiais; não implementar literalmente uma validação impossível.
- O material não contém ajuste de comissão, consulta de ofertas, condições Sem entrada, resposta 409 com identificador ou PDF: não encerra essas pendências já registradas.
- Ainda faltam definições do sistema sobre capital de abertura, índice Porto, contatos do cônjuge, CPF do operador e tratamento de ofertas elegíveis. O novo arquivo não altera a vigência de 30 meses nem fornece um valor padrão para dia de vencimento ou capital.

**Verificação:** todos os objetos e parâmetros presentes no recorte foram confrontados com os trechos pertinentes do Swagger. Apenas a documentação foi alterada; sem implementação, alteração de formulários, testes contra a Porto ou chamada de orçamento.

### Porto — situação consolidada das dúvidas após o confronto

Esta classificação separa regra documental, decisão de produto e validação remota; prevalece sobre listas anteriores que ainda chamem um item confirmado de dúvida aberta.

| Tema | Situação atual |
| --- | --- |
| Headers de token | Resolvido pelo usuário: Authorization Bearer e access_token simultâneos |
| Aprovação/recusa | Resolvido: HTTP 201/409 no POST; 400/401/500/502 não são recusas cadastrais |
| Finalidade PF | Resolvido no contrato: "1" residencial e "2" comercial. Habilitação comercial ainda aguarda suporte |
| CPF do cotador obrigatório | Resolvido no contrato; falta identificar o operador que fornecerá o valor |
| Nome/nascimento e dados profissionais | Obrigatoriedade resolvida; somente totalRendimentos obrigatório dentro de dadosProfissionais segundo Swagger |
| Comercial PF versus residencial | Condições textuais esclarecidas: capitalAberturaNegocio para PFCOM, omitir locacaoComercial em PFRES; divergência do required genérico reservada à homologação |
| Titularidade do casal | Resolvido: um titular S por requisição, outro N somente na conjunta; cada individual tem seu próprio S |
| Coberturas | Códigos e valores documentados; tenant somente 001, adicionais 007–010 nas demais origens |
| Vigência | Regra do sistema já encerrada: 30 meses da rodada; formato Porto DD/MM/AAAA. Confirmar apenas o código aplicável ao início no dia |
| Código de imobiliária | Natureza e formato resolvidos; lista do cliente ainda será fornecida; não é CNPJ ou ID local |
| Valor de parcela e PDF | Caminhos identificados: valorParcela na condição e data.file Base64 no documento. Formato real e disponibilidade permanecem verificações técnicas |
| Comissão | Campo e escala localizados, 15 em codComissao; operação mínima e efeito sobre o preço ainda não esclarecidos |

**Atualização das decisões do projeto:** operador, índice, capital de abertura e compartilhamento dos contatos foram definidos pelo usuário na seção seguinte, que prevalece sobre as dúvidas históricas acima. A lista de códigos das imobiliárias continua aguardando envio pela Porto. A seleção de pagamento por origem foi confirmada; o catálogo possui também códigos de produto distintos, cuja seleção não deve ser confundida com a forma de pagamento.

**Esclarecimentos que exigem suporte ou resposta real da Porto:** body mínimo para aplicar 15% e momento em que preços são recalculados; locador no POST; condições de obrigatoriedade comercial; identificação de 30x Sem entrada por forma/oferta; objeto versus array em condicoesPagamento; origem do número de orçamento recusado; disponibilidade do PDF após criar orçamento; periodicidade de totalRendimentos e aceitação de valores decimais. A regra de renda de quatro aluguéis já existe no projeto, portanto falta confirmar seu encaixe contratual em totalRendimentos, não decidir novamente o multiplicador. Limites contraditórios de e-mail, reajuste e valores devem ser validados sem truncar dados reais. Não é necessário decidir orcamentoExterno agora: é opcional e sua omissão evita depender do limite conflitante 15/32 nesta primeira integração.

**Não reabrir como decisões de negócio:** duração de 30 meses, comissão de 15%, exceção de coberturas tenant, individuais paralelas e conjunta somente após duas recusas. Suporte/homologação devem esclarecer como representar essas regras no contrato Porto, sem mudá-las por inferência. Não solicitar CPF/credenciais sensíveis em texto público; a fonte do operador pode ser definida agora e o valor configurado depois no ambiente apropriado.

### Porto — regras confirmadas pelo usuário em 30/10/2026

Esta atualização encerra as pendências de decisão abaixo e prevalece sobre menções anteriores a fontes ainda indefinidas. É registro de regras para implementação futura, não alteração dos builders ou configuração em execução.

| Parâmetro/regra | Definição confirmada |
| --- | --- |
| `cpfCotador` | O operador é o corretor. Utilizar a configuração `CPF_COTADOR` adicionada pelo usuário ao .env; nome da chave conferido sem divulgar seu conteúdo. Na implementação, expor pelo config da Porto e consumir via config(), sem ler env() no builder |
| `contrato.codigoReajuste` | IGP-M: enviar **"002"**. `IGP_M` expressa a escolha de negócio, mas o valor wire da Porto é o código de três dígitos |
| `locacaoComercial.capitalAberturaNegocio` | **100 × valor do aluguel**, no cenário comercial PF em que o campo se aplica; não confundir com renda de quatro aluguéis ou capitalSocial |
| Contatos do cônjuge | Reutilizar **e-mail e telefone do pretendente principal**, tanto na análise individual do cônjuge quanto na conjunta. Nome, CPF e nascimento continuam próprios de cada pessoa |
| Imobiliária cadastrada | Comparar condições de **fatura FAT**, usando seu código Porto; não incluir cartão como fallback automático |
| Demais origens | Comparar condições de **cartão de crédito**, preservando o critério já registrado de CC para cartões gerais, sem presumir que o solicitante possua cartão Porto CP |
| Códigos de imobiliárias | Lista aguardando envio pela Porto; a afirmação de que todas as atuais têm código não permite inventar ou antecipar a associação |

Exemplo da regra de capital: aluguel de R$ 1.500,00 resulta em capitalAberturaNegocio de 150000. O valor é uma regra derivada autorizada pelo usuário, não capital declarado pelo solicitante. Preservar essa origem no desenho da integração e usar o aluguel congelado no snapshot. Não aplicar o cálculo a PF residencial. O contrato declara inteiro; para aluguel com centavos, calcular 100 vezes o valor monetário com precisão decimal, evitando erros de ponto flutuante e sem truncar o aluguel.

O compartilhamento dos contatos está agora explicitamente autorizado; não exige criar campos de contato do cônjuge. Utilizar os contatos do principal congelados na mesma rodada, inclusive quando o cenário individual substitui nome/CPF/nascimento. As validações contratuais de e-mail e telefone permanecem aplicáveis aos valores compartilhados.

**Comparação — decisão posterior do usuário:** somente o produto **Fiança** foi escolhido para representar a Porto na comparação com as outras seguradoras. **A associação anteriormente feita com `seguro = "0008"` foi descartada pelo usuário em 31/10/2026; não implementar esse filtro fixo.** A identidade das ofertas deve vir do GET de ofertas, conforme esclarecimento abaixo. Manter o filtro de pagamento por origem (FAT para imobiliária cadastrada; cartão para demais origens), vigência de 30 meses, comissão de 15% e condição Sem entrada, usando a parcela real elegível. Essa correção da fonte dos produtos não autoriza ampliar a comparação para todos os Essenciais.

**Produtos no card da Porto:** preservar e exibir também **Essencial 12 ("0001"), Essencial 20 ("0007") e Essencial 30 ("0005")**, somente quando retornados pela API. Pode aparecer um, alguns ou todos, conforme disponibilidade. Esses produtos são alternativas apresentadas no card e **não participam da escolha do melhor negócio entre seguradoras**, mesmo se possuírem parcela menor. Seus nomes não indicam automaticamente a quantidade de parcelas; apresentar as condições efetivamente retornadas, sem fabricar ofertas ausentes.

A seleção para ranking não deve descartar as demais ofertas persistidas nem seus identificadores. Painel, e-mail e resumo compartilhado precisam usar Fiança quando apresentarem o preço Porto na comparação; dados de Essencial exibidos separadamente não podem substituir esse preço. Se Fiança não retornar ou não possuir preço elegível, a Porto fica sem oferta comparável, preservando a exibição dos Essenciais disponíveis e a decisão de crédito recebida. Não usar Essencial como fallback nem transformar indisponibilidade de produto/preço em recusa. A pendência de escolha dos produtos está encerrada.

**Pendências restantes:** lista das imobiliárias; aplicação efetiva dos 15% antes de obter preço; identificação de 30x Sem entrada; periodicidade de totalRendimentos; locador no POST e divergências do required condicional; código de vigência para início no dia; identificador da carta após 409 e validações técnicas de ofertas/PDF. Não voltar a pedir definição de operador, índice, capital, contatos do cônjuge ou produtos para comparação/exibição.

### Porto — confirmação do suporte para CNPJ e finalidade — 31/10/2026

Fonte: relato do usuário sobre o e-mail do suporte e imagem atualizada de `PropostaLocatario.cpfCnpj`, com maxLength 14 e descrição de CPF/CNPJ. O e-mail não foi consultado diretamente. A imagem pertence ao schema de proposta; a extensão da regra ao orçamento se apoia na confirmação expressa do suporte retransmitida pelo usuário, não apenas na imagem. Esta definição prevalece sobre as restrições históricas a CPF e a pendência de disponibilidade PJ.

**Documento:** enviar string com exatamente **11 dígitos para CPF ou 14 para CNPJ**, sem máscara, espaços ou caracteres especiais, preservando zeros à esquerda. Não trocar o CNPJ da empresa pelo CPF do responsável só para satisfazer o schema antigo. A imagem menciona completar zeros quando houver menos caracteres; não usar isso para converter documento incompleto em identidade presumida sem identificar seu tipo e validar o documento.

| Documento e finalidade real | `tipoLocacao` enviado | Complemento confirmado |
| --- | --- | --- |
| CPF residencial | "1" — PFRES | Sem complemento comercial pela regra textual já estudada |
| CPF comercial | "2" — PFCOM | locacaoComercial conforme regras PFCOM |
| CNPJ comercial | "2" — PFCOM | Dados PJ em locacaoComercial; motivo selecionado no futuro campo Motivo da Contratação, códigos "1" a "9" |
| CNPJ residencial | "2" — PFCOM | `locacaoComercial.motivoLocacao = "A"` — Locação para Moradia |

PFRES/PFCOM são descrições; os códigos de transporte continuam as strings "1"/"2" documentadas. O enquadramento PFCOM do CNPJ residencial é específico do adapter Porto: **não alterar a finalidade residencial do lead**, a origem do formulário, as coberturas ou o payload das outras companhias. O código A fica restrito à finalidade residencial PJ; não aplicar A a toda pessoa jurídica. Não criar um terceiro código tipoLocacao para PJ.

A imagem também informa que os locatários da proposta devem corresponder aos previamente analisados no orçamento. Acrescentar ou remover pretendentes exige novo orçamento/análise; isso é compatível com individuais e conjunta em cotações distintas e com a regra geral de reanálise por nova solicitação. Não implementar endpoints de emissão para cumprir essa regra.

**Pendências abertas pela definição PJ:**

1. Nome, nascimento e rendimentos PJ foram definidos pelo usuário na atualização abaixo: razão social, nascimento do responsável e renda de 10 aluguéis. Resta identificar campo específico para CPF/nome do responsável na Porto, caso ela os exija adicionalmente; não substituir o CNPJ por esse CPF nem criar um atributo sem contrato.
2. **Regras esclarecidas em 31/10/2026:** `capitalSocial` fixo em 450000 para PJ; `capitalAberturaNegocio = 100 × aluguel` também para CNPJ; motivo comercial escolhido no futuro seletor Motivo da Contratação. Para residencial com CNPJ, motivo "A" fixo internamente e ausente do seletor. Detalhes na confirmação de capitais e motivo abaixo; implementação ainda pendente.
3. **Encerrado em 02/11/2026:** não existe PJ com cônjuge no sistema. Analisar somente a empresa pelo CNPJ; responsável não recebe análise individual e não há conjunta PJ. A regra de cônjuges aplica-se somente a PF.

**Demais pendências preservadas:** como aplicar a comissão de 15% antes da consulta de ofertas (body mínimo e recalculo de valores); identificação da condição 30x Sem entrada; locador obrigatório no POST; código de vigência para início no dia; identificador de orçamento após 409 para a carta; lista dos códigos de imobiliárias e verificações reais de ofertas/PDF. O cálculo de totalRendimentos foi definido na confirmação abaixo. Autenticação dupla, operador corretor, reajuste 002, contatos compartilhados, capital de abertura 100×, produto Fiança no ranking e Essenciais somente para exibição já estão decididos e não precisam ser reconfirmados.

Registro exclusivamente documental. Nenhum código, formulário ou configuração foi alterado, nem foram realizadas chamadas à Porto. A confirmação contratual de CNPJ não significa que todos os dados obrigatórios do payload PJ já estejam definidos.

### Porto — razão social, responsável e rendimentos confirmados — 31/10/2026

O usuário confirmou o preenchimento do item 1 das dúvidas PJ, com referência a `app/Models/LeadEmpresa.php`, à tela do responsável e à descrição de totalRendimentos. Esta definição prevalece sobre as pendências anteriores relativas à escolha do nome, à origem do nascimento e ao cálculo da renda para Porto.

**Pessoa jurídica:** em `locatarios[]`, manter `cpfCnpj` com o CNPJ e preencher `nome` com a **razão social** do locatário empresarial. Preencher `dataNascimento` com o **nascimento do responsável pela empresa**, consultado por seu `cpf_responsavel` na API CPF Brasil e serializado no formato Porto `DD/MM/AAAA`. A data não representa fundação da empresa. O nome retornado na consulta pertence a `nome_responsavel`, não à razão social; não sobrescrever o nome empresarial com o nome da pessoa física.

**API CPF já aplicada ao responsável:** a inspeção do código confirma que `CpfLookupService::resolveBirthdates()` escolhe `cpf_responsavel` quando o documento do solicitante é CNPJ, resolve nome e nascimento e retorna `nome_responsavel` e `data_nascimento`. `SimulationController` persiste CPF/nome do responsável em `lead_empresa` e o nascimento em `Lead.data_nascimento`; `LeadEmpresa` não possui campo próprio de nascimento. A view compartilhada `cpf-lookup.blade.php` mapeia `cpf_responsavel` para `nome_responsavel`. Portanto, reutilizar esse fluxo, sem consulta por nome, sem consulta do CNPJ na API CPF e sem nova chamada a cada cotação. A feature flag desativada continua permitindo entrada manual conforme regras já implementadas. Esta inspeção estática não constitui nova consulta remota ou homologação do formulário.

**Dados profissionais Porto:** enviar somente `dadosProfissionais.totalRendimentos`, único campo obrigatório interno documentado, com a seguinte regra definida pelo usuário:

| Cenário | totalRendimentos |
| --- | --- |
| CPF residencial, PFRES ("1") | **4 × valor do aluguel** |
| CPF comercial, PFCOM ("2") | **10 × valor do aluguel** |
| PJ comercial ou residencial, enquadrado como PFCOM ("2") | **10 × valor do aluguel** |

Não preencher empresa, vinculoEmpregaticio, expatriado ou dataAdmissao por padrão. Calcular com o aluguel congelado na rodada, preservar precisão monetária e não somar encargos à base. Para aluguel de R$ 1.500,00, totalRendimentos será 6000 em PFRES ou 15000 em PFCOM/PJ. A renda calculada é uma regra do sistema autorizada pelo usuário, não renda efetivamente consultada na API CPF. É uma regra específica Porto e **não altera os multiplicadores das outras seguradoras**. A data de nascimento consultada não fornece renda.

Encerra-se a pendência de decidir o valor de totalRendimentos; a definição operacional é o multiplicador acima. Permanece somente a divergência técnica do tipo integer/int32 com exemplos decimais: não truncar centavos silenciosamente. Essa renda é distinta de `capitalAberturaNegocio = 100 × aluguel` e não define `capitalSocial`.

**Composição PJ encerrada em 02/11/2026:** somente empresa, sem análise de responsável/cônjuge ou conjunta. Eventuais dados complementares do responsável seguem o contrato, sem criar pretendente adicional ou substituir a identidade empresarial. Capital social, motivo comercial e aplicação do capital de abertura ao CNPJ foram esclarecidos. As demais pendências técnicas devem ser consultadas na consolidação mais recente no início do estudo. Nenhuma implementação da Porto ou alteração de formulário foi feita nesta revisão.

### Porto — capitais e Motivo da Contratação confirmados — 31/10/2026

Esta confirmação do usuário encerra as dúvidas sobre os capitais e a origem do motivo da locação, prevalecendo sobre as pendências históricas acima. Trata-se de definição para implementação futura, sem criação do campo nesta revisão documental.

- `locacaoComercial.capitalSocial`: valor fixo **450000 (R$ 450.000,00)** para PJ, sem cálculo sobre o aluguel. É um padrão de negócio definido pelo usuário, não informação consultada ou declarada pelo solicitante.
- `locacaoComercial.capitalAberturaNegocio`: **100 × valor do aluguel**, tanto para CPF comercial quanto para CNPJ, inclusive CNPJ residencial enquadrado como PFCOM na Porto. Manter a precisão monetária e usar o aluguel congelado na rodada. Não se aplica a CPF residencial.
- Futuro campo **Motivo da Contratação**: seletor exibido quando a finalidade real da locação for **comercial**, para CPF ou CNPJ. A Porto recebe a chave documentada em `locacaoComercial.motivoLocacao`, não o texto da opção. O dado poderá ser reutilizado por outras seguradoras conforme seus próprios contratos; não propagar códigos Porto automaticamente para elas.

| Chave Porto | Opção do seletor comercial |
| --- | --- |
| "1" | Abertura de Matriz |
| "2" | Cedido |
| "3" | Redução de Custos |
| "4" | Venda do imóvel atual |
| "5" | Troca de local de sede |
| "6" | Locação adicional |
| "7" | Abertura de filial |
| "8" | Troca de garantia |
| "9" | Abertura de nova empresa |

**Locação para moradia ("A") não deve aparecer no seletor.** Quando o documento for CNPJ e a finalidade real for residencial, o sistema define internamente `tipoLocacao = "2"` (PFCOM) e `locacaoComercial.motivoLocacao = "A"`, de forma fixa e imutável pelo solicitante. O enquadramento técnico PFCOM não deve fazer o campo comercial aparecer nesse cenário. CPF residencial permanece PFRES, sem esse seletor e sem motivo comercial.

Na implementação, validar no backend a coerência entre documento, finalidade e motivo: comercial aceita somente as opções "1" a "9"; residencial com CNPJ deriva "A" no servidor, sem depender de campo oculto; mudanças de finalidade não podem reaproveitar um motivo anterior incompatível. Persistir a escolha comercial e preservá-la no snapshot da rodada, respeitando a arquitetura existente.

### Porto — consulta de ofertas e prazo das parcelas — confirmação de 31/10/2026

Fontes lidas integralmente: `endpoint-exibicao-de-ofertas-url.txt`, `200-endpoint-exibicao-de-ofertas.txt` e `espeficicacoes-para-requisicao-exibicao-de-ofertas.txt`, fornecidos em Downloads, e esclarecimento do usuário nesta data. Os arquivos são documentação/exemplo, não uma consulta executada pelo sistema. Esta revisão atualiza o estudo; não implementa nem chama a API.

**Operação confirmada:** `GET https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/emissao/aluguel-essencial/v1/orcamentos/{origem}/{orcamento}/{susep}/ofertas`, após orçamento criado e aprovado. Preservar os dois headers de autenticação anteriormente confirmados. Os parâmetros estão no caminho; os arquivos não apresentam body de requisição.

| Parâmetro | Contrato e aplicação |
| --- | --- |
| `origem` | "22" para emissão/nova cotação e "40" para renovação; não é a origem do formulário. Nossa nova análise usa "22", sem emitir apólice por isso. A descrição exige dois caracteres, embora os limites genéricos sejam 1–15 |
| `orcamento` | Número interno Porto com 15 dígitos, como string, preservando zeros à esquerda; vinculado ao orçamento/cenário aprovado |
| `susep` | Código interno do corretor na Porto, seis caracteres alfanuméricos terminados em F ou J; não confundir com código da imobiliária |

**Ofertas disponíveis:** o usuário enumerou **quatro produtos possíveis** — Essencial 12, Essencial 20, Essencial 30 e Tradicional — apesar de mencionar “3 planos”. Tratar `ofertas[]` como coleção variável: apresentar apenas os produtos efetivamente retornados, sem exigir três ou quatro, sem fabricar ausentes e sem criar um POST por produto. Uma única criação pode oferecer vários produtos; manter orçamento, cenário, produto e condições relacionados.

**Fonte definitiva das ofertas — esclarecimento posterior do usuário em 31/10/2026:** desconsiderar a associação de Fiança ao código 0008 inferida do exemplo de criação. O sistema utilizará **este GET de ofertas** para visualizar os produtos disponíveis, com seus códigos, nomes, formas de pagamento e condições efetivamente retornados. Não manter catálogo fixo derivado do exemplo do POST, não renomear 0008 para Fiança e não substituir o nome retornado por uma interpretação anterior. O exemplo do GET também não fixa um catálogo universal: seus valores ilustram a estrutura; cada orçamento deve preservar as ofertas recebidas. A regra anterior de não comparar todos os Essenciais permanece separada da identificação técnica do produto; não assumir equivalência Tradicional/Fiança apenas por código ou nome de exemplo.

**30 parcelas esclarecidas pelo usuário:** para **cartão de crédito e fatura**, a Porto retorna automaticamente **30 parcelas quando início e fim do contrato delimitam 30 meses**. Manter as datas da rodada, do dia da análise até 30 meses depois, e selecionar a forma definida por origem: fatura para imobiliária cadastrada e cartão para os demais. Não há nova dúvida de regra sobre a quantidade desejada; verificar tecnicamente a resposta real ao homologar. Não enviar um parâmetro de quantidade inventado neste GET.

**Entrada ainda pendente:** o usuário está confirmando como alterar entre Com entrada e Sem entrada. O padrão desejado do sistema continua Sem entrada, mas o mecanismo e o identificador Porto dessa condição ainda não estão confirmados. A confirmação de 30 parcelas não resolve a entrada. Não reduzir para 29 por analogia com Too, não inventar `tipoEntrada` e não inferir entrada apenas de `primeiroVencimento`.

**Estrutura do exemplo HTTP 200:**

- Raiz com `susep`, `orcamento`, `ofertas[]` e `origem` (22 numérico no exemplo de resposta; parâmetro de caminho documentado como string).
- Cada oferta contém `seguro`, `nomeSeguro`, limites de `comissao`, `formasPagamento[]`, `coberturas[]`, `beneficios[]`, `agravos[]`, `descontos[]`, `clausulas[]`, `acordo` e `calculo`.
- Cada forma possui `formaPagamento`, `nomeFormaPagamento` e **`condicoesPagamento` como objeto**, neste material. Isso confirma o formato do exemplo e do schema GET anteriormente recebido, sem provar que outros retornos reais nunca usem uma lista. Não tratar esse objeto como se fosse a lista de condições do POST sem normalização explícita e validada.
- A condição contém `condicaoPagamento` (identificador), `quantidadeParcela`, `valorParcela`, `primeiroVencimento`, `nomeCondicaoPagamento`, prêmios líquido/bruto/total, IOF, desconto, percentual de desconto, adicional de fracionamento, código externo e meio de recebimento. Para comparação, usar **`valorParcela`** da forma e condição elegíveis; não dividir prêmio total nem somar IOF novamente. Guardar o identificador da condição para o PDF correspondente.
- `comissao.percentualMinimo/Maximo` e `acordo.percentualMinimo/Maximo` são limites retornados; o exemplo 0–30 não confirma comissão aplicada de 15% nem fixa esses limites para todos os produtos. `calculo` contém adicionais, juros, custo de apólice, IS, LMG e taxa de reativação; esses valores não substituem a parcela selecionada.
- `coberturas[]` traz código, nome, obrigatoriedade, múltiplos/limites LMI, IS, LMI calculado e prêmio. Preservar as coberturas por oferta, sem pressupor equivalência dos produtos ou interpretar Essencial 12/20/30 como quantidade de parcelas.

**Divergências do exemplo e efeitos na implementação futura:**

1. O exemplo do GET associa `seguro = "0008"` a `nomeSeguro = "ESSENCIAL 12"`. **Dúvida sobre a fonte resolvida pelo usuário:** o exemplo de criação não deve prevalecer nem impor a associação 0008/Fiança. Na integração, respeitar código e nome retornados pelo GET para o orçamento; não tratar esse rótulo como erro por divergir do antigo exemplo do POST.
2. `nomeCondicaoPagamento = "10X ADC"`, `quantidadeParcela = 0`, `valorParcela = 172.8` e `premioTotal = 1728` são inconsistentes entre si quanto à quantidade. Não interpretar zero como parcela única ou trinta, nem calcular a quantidade dividindo o total pelo valor. Esse exemplo de débito em conta não invalida a confirmação do usuário sobre 30 parcelas para cartão/fatura e não deve ser reutilizado como prova dessa condição.
3. O exemplo contém apenas ADC, não as condições de cartão/fatura que o sistema utilizará; falta validar seus identificadores, a condição de entrada e os dados coerentes em retorno real. Falha na consulta ou condição inutilizável impede comparar aquela oferta, mas não transforma aprovação cadastral em recusa nem justifica nova criação automática de orçamento.

**Pendências deste ponto:** como configurar/identificar Sem entrada e validar condições reais de cartão e fatura em 30 meses. A fonte para identificar/exibir ofertas é o GET enviado; a associação fixa 0008/Fiança está descartada, sem necessidade de reconfirmá-la. Ao implementar a seleção para ranking, verificar o produto elegível nas ofertas reais respeitando a decisão de não comparar todos os Essenciais, sem inventar equivalências. A regra de prazo e a disponibilidade variável dos produtos estão esclarecidas. Continuam separadas as pendências já registradas de comissão efetiva de 15%, documentos, dados obrigatórios e códigos das imobiliárias.

## Estado da implementação e próximos payloads — revisão de 29/10/2026

Esta seção prevalece sobre as referências históricas a trabalho ainda não implementado nas revisões anteriores. Distinguir **implementado no backend**, **validado localmente** e **homologado na seguradora**. A revisão atual altera somente este estudo; os passos 2 (Too) e 3 (Pottencial) aguardam comando do usuário.

### Entregas concluídas no backend; validação integrada pendente

| Entrega | Estado e evidência no código |
| --- | --- |
| Organização dos services por domínio | Implementada, preservando o domínio `Services/Insurance` e os contratos dos consumidores. Não requer nova reorganização para refatorar os payloads |
| Persistência de múltiplas cotações | Implementada: `insurance_analysis_rounds`, `insurance_provider_runs`, `insurance_applicant_scenarios`, `insurance_quotes` e `insurance_quote_events`. A migration `2026_10_29_121802_create_insurance_quote_orchestration_tables` foi conferida via `migrate:status` nesta revisão: **Ran, lote 12**, após o usuário ativar MySQL e aplicá-la |
| Pacote de análises por lead | Preservado: lote operacional, uma análise consolidada por companhia e cotações independentes sob cada rodada. `StartInsuranceAnalysesBatchJob` mantém o batch; `RunProviderAnalysisJob` encaminha para `InsuranceQuoteCoordinator` e `RunInsuranceQuoteJob` |
| Cenários e planos | Implementados: individuais de titular/cônjuge, conjunta somente após ambas recusadas, sem interpretar falha técnica como recusa. Pottencial usa Basic, Complete e Traditional: 3 cotações individuais sem cônjuge; 6 iniciais com cônjuge e até 3 conjuntas, total máximo 9 |
| Rodadas e execução | Implementados snapshot do lead, datas congeladas de 30 meses, IDs/eventos próprios, proteção contra respostas antigas, coordenação da conjunta e retomada dos jobs. Nova reanálise usa nova rodada e criação de cotação; métodos antigos de reanálise ainda existentes não comprovam seu uso nesse novo fluxo |
| Pagamentos Too | `TooService::getPaymentOptions()` e `InsuranceQuotePayment` implementados. Seleção Mensal, Sem entrada, 29 parcelas efetivas na base de 30 meses; Fatura para origem cadastrada vinculada, Boleto para demais origens. Preservação da resposta e do valor unitário, sem divisão do prêmio |
| Pagamentos Pottencial | `PottencialService::getPaymentSimulations()` implementado, mantendo a rota configurada. Seleção Invoice/Boleto conforme origem e alternativa com 30 parcelas; uso do valor unitário retornado |
| Consolidação e comparação | Implementadas escolha da menor parcela aprovada, separação entre aprovação e indisponibilidade de preço, e comparação por parcela em `AnalysisQuoteSummary`/`AnalysisQuoteComparison`. Registros antigos preservam tratamento legado; não misturar total antigo com parcela nova |
| Leitura dos resultados | Backend entrega cenários/planos por `quote_attempts`, com nomes, status, mensagem, preço e seleção. Isso **não significa** que as views, os downloads por plano e a apresentação dos e-mails já tenham sido adaptados |

Validação local registrada na entrega anterior: 25 testes de múltiplas cotações aprovados; após os últimos ajustes, esses testes e os testes da visualização do lead totalizaram 76 aprovados. Na execução ampla anterior houve duas falhas: uma de status/redirecionamento já observada na base e outra de lock SQLite no teste de filas; o arquivo de filas passou isoladamente. Esses resultados não equivalem a homologação remota nem a teste de concorrência em MySQL. Nesta revisão documental foi conferido o status da migration; não foram executadas chamadas às seguradoras ou novos testes de aplicação.

### Passo 2 — refatoração Too, aguardando autorização

1. Alinhar `TooRentalGuaranteePayloadBuilder` e configuração à comissão **15.0**, em pontos percentuais, respeitando a faixa 10–40. Hoje o default em `config/services.php` é 0.10 e o fallback do builder é 0.25: ambos divergem da regra. Não assumir o valor efetivo de um ambiente sem conferir sua configuração resolvida. Preservar IGP_M, período indenitário 30 e datas da rodada.
2. Aplicar a regra de coberturas por origem. O builder ainda zera multas e pinturas; para as origens autorizadas deve solicitar os adicionais conforme o contrato. Para tenant, enviar somente aluguel, sem despesas/adicionais e sem reintroduzir água/luz por fallback.
3. Revisar a identidade e os dados de cada cenário no payload de ficha e cotação, usando os snapshots já implementados. Não recriar a orquestração nem usar endpoints de emissão/reanálise dedicada.
4. **Nascimento do cônjuge:** o usuário propôs reutilizar a data do principal para evitar outro campo. A estrutura atual não possui data própria do cônjuge; o snapshot não copia a do principal e a validação Too exige nascimento. Registrar a proposta como pendente de validação contratual, pois a data reaproveitada não é um dado confirmado do cônjuge. Não declarar esse cenário homologado nem alterar silenciosamente a identidade enviada.
5. Validar o GET de pagamentos já implementado com respostas reais de ambas as formas. A regra está encerrada: Mensal para todos, Fatura/Boleto por origem, Sem entrada e 29 parcelas efetivas. Catálogo ou preço indisponível não deve virar recusa cadastral.
6. Implementar posteriormente o PDF 6.1 por **POST**, usando numeroFicha e formaPagamento: tipoPagamento 1/2, tipoEntrada 2, tipoOpcaoParcelamento 2 e quantidadeParcelas **30**. Decodificar `result.pdfCotacao` em Base64. O método atual `getQuotePdf` ainda usa GET e não implementa esse contrato; não marcá-lo como concluído. O passo 7 de atualização para emissão permanece fora do escopo.

### Passo 3 — refatoração Pottencial, aguardando autorização

1. Alinhar `RentalGuaranteeQuotePayloadBuilder`: Broker com documento, role, lead=true, participationPercentage=1 e commissionPercentage=0.15. Remover a comissão hoje adicionada a PolicyOwner; este deve conter apenas documento e role, somente para imobiliária cadastrada vinculada. Validar seu documento antes do envio.
2. Preservar participants mínimos (documentNumber e PolicyHolder), já aplicados às novas cotações, e coerência com tenantDocumentNumber/riskObjects em cada cenário. Não enviar dados de contato de proprietário/imobiliária não cadastrada como participantes ou agentes.
3. Aplicar a mesma exceção tenant somente aluguel. Nas demais origens, conferir a representação das coberturas adicionais no contrato Pottencial e nas três alternativas de plano, sem copiar nomes de campos da Tokio ou Too.
4. Preservar Invoice/Boleto por origem, 30 parcelas, Sem entrada e vigência de 30 meses da rodada. Não reduzir para 29 na Pottencial. Corrigir fallbacks antigos ainda existentes para que caminhos alternativos não revertam os padrões aprovados.
5. Manter a rota atual; a alternativa permanece para teste controlado caso necessário. Validar os três planos e os retornos de simulação sem recriar o serviço de consulta já entregue. `Creating` já foi mapeado como processamento.
6. Completar a obtenção e disponibilização dos documentos de cada cotação/plano e a carta de recusa. O formato real de letters continua para validação durante o desenvolvimento; a presença de um método de consulta não comprova download funcional por plano.

### Pendências que permanecem abertas

- Homologar os payloads finais, elegibilidade e valores de parcelas com as seguradoras; validar concorrência, retomada e conclusão no ambiente MySQL/filas.
- Aplicar o backend completo da Tokio: provider, builder final, normalização, seleção das alternativas e exceção PJ manual. Comandos de diagnóstico e componentes parciais não equivalem à integração concluída.
- Associar códigos de parceiro Tokio às imobiliárias, conforme a planilha e regras já definidas.
- Implementar apresentação de todas as tentativas, vencedor e PDFs nos painéis/e-mails; o DTO e o comparador disponíveis são a base dessa etapa.
- Desenvolver futuramente Gerenciar seguradoras. Centralizar agora os padrões aprovados nos payloads não significa que a página já exista.


Levantamento original: 25/09/2026. Estado atual: preparação da integração Tokio; os dados de formulário e parte da montagem dos pretendentes já foram implementados, mas o provider remoto ainda não existe.

Atualização documental: 09/10/2026 — estudo consolidado da Tokio Marine na seção [Tokio Marine](#tokio-marine). As seções originais de Too/Pottencial abaixo preservam o levantamento de 25/09; referências a “código atual” nessas seções são históricas, anteriores às refatorações. Para as decisões posteriores sobre parcelas, PDF e reanálise, prevalece o contexto atualizado da seção Tokio. Esta atualização não implementa nem testa a integração.

Decisão posterior de 13/10/2026: a [regra geral de pagamento e comparação em 30 parcelas](#regra-geral-de-pagamento-e-comparacao-em-30-parcelas) substitui a regra anterior de “até 30x” para **todas as companhias**. É uma definição funcional; a implementação atual ainda não foi adaptada nesta entrega.

**Revisão de 15/10/2026:** consolidada a branch `feature/testes-com-Apis`, até `a72f029`, incluindo a validação de nascimento presente na árvore de trabalho e ainda não commitada. A seção T14 registra os esclarecimentos posteriores, as estruturas já disponíveis e as lacunas reais para iniciar a integração. As decisões do usuário, a implementação local e o suporte documentado pela Tokio estão identificados separadamente. Esta revisão altera somente este estudo; não ativa a seguradora nem comprova homologação.

**Revisão de 23/10/2026:** incorporados o TXT e a print de regras da cotação Too, a decisão de usar sempre `IGP_M` e a regra transversal de fatura condicionada à presença de imobiliária na cotação; sem imobiliária, usar crédito, com as exceções posteriores de Pottencial e Too: boleto. Esta revisão é documental e prevalece sobre referências históricas conflitantes.

## Escopo e fontes

- `Pottencial.postman_collection (6).json`: coleção Postman, grupo `Fiança locaticia Mensalizado` e autenticação compartilhada.
- `openapi_final (1).yaml`: OpenAPI 3.0.0 da Too Seguros, título `Fiança Locatícia`, versão documental `1.0`, tag `Aluguel Garantido`.
- `../app/Services/PottencialService.php`, `../app/Services/TooService.php` e `../config/services.php`.
- Contexto complementar: providers em `../app/Services/Insurance/Providers/`, `TooRentalGuaranteePayloadBuilder.php` e `SyncTooAnalysisStatusJob.php`.

Aplicada a skill `api-design` para separar contrato, implementação e lacunas. As seguradoras são fornecedoras de capacidades de negócio; seus contratos externos são preservados. A classificação de camadas da skill é uma referência conceitual, não uma regra imposta às companhias.

Nenhuma chamada às seguradoras foi feita. Credenciais, tokens e dados pessoais dos exemplos não foram copiados. As conclusões descrevem os arquivos recebidos, sem afirmar que representam a versão mais recente disponível ou que os endpoints funcionam em homologação. Código e contratos originais não foram alterados.

## Escopo funcional definido pelo usuário

O sistema utiliza somente as seguintes capacidades de Fiança Locatícia:

1. Criar orçamento.
2. Solicitar análise do orçamento.
3. Atualizar orçamento.
4. Visualizar o resultado da análise do orçamento.
5. Gerar/obter PDF do resultado da análise, tanto recusado quanto aprovado.

Esse recorte orienta os próximos passos. Autenticação é uma dependência técnica. Cadastro de ficha/proposta pode ser uma etapa necessária à análise na Too, mas não autoriza avançar para contratação. Aceite, transmissão para emissão, emissão e consulta de apólices ficam fora do escopo. Biometria, envio de documentos e outras operações auxiliares só entram no fluxo se forem comprovadamente necessárias às cinco capacidades acima.

“Orçamento” é o conceito usado pelo sistema; a companhia pode representá-lo por cotação, ficha e/ou proposta. A equivalência deve ser estabelecida pelo comportamento e pelas dependências de cada operação, não apenas pelo nome da rota. Atualizar orçamento não deve ser confundido com completar proposta aprovada para contratação.

### Mapeamento das cinco capacidades

Na coluna Pottencial, os caminhos são relativos a `/insurance/v1/fianca-locaticia-mensalizado-pf`.

| Capacidade | Pottencial | Too |
| --- | --- | --- |
| Criar orçamento | POST `/quotes`; implementado em `createRentalGuaranteeQuote` | YAML: POST `/fianca/cotacao/aluguel-garantido`. Código atual: ficha em POST `/fianca/proposta/ficha` e cotação em POST `/fianca/proposta/cotacao` após aprovação. Contratos diferentes, ainda a conciliar |
| Solicitar análise | A coleção de Fiança não contém operação separada de análise. Confirmar se POST `/quotes` já dispara análise; não inferir rota `/analysis` de outros produtos | POST `/fianca/credito/{cpf}/{numeroProposta}/analisar`; implementado em `submitCreditAnalysis`. Exige identificação da ficha/proposta |
| Atualizar orçamento | Nenhuma operação de atualização identificada no grupo de Fiança. Não presumir que criar nova cotação atualiza a existente | Código: PUT `/fianca/proposta/ficha/{numeroFicha}/dados-basicos`, via `updateProposalBasicData`, ausente do YAML. O PUT `/fianca/proposta/{numeroProposta}/atualizar` do YAML só aceita propostas aprovadas e não comprova atualização geral do orçamento |
| Visualizar resultado da análise | GET `/quotes/{quote_id}`, implementado em `getRentalGuaranteeQuote`, é o candidato de consulta; faltam schema de resultado e catálogo de estados para confirmar aprovação/recusa | Código: GET `/fianca/proposta/v3/{cpf}/status/{numeroProposta}`. YAML: GET `/fianca/proposta/{cpf}/{numeroProposta}`. Confirmar contrato e estados aplicáveis |
| PDF da análise aprovada ou recusada | GET `/quotes/{quote_id}/draft` entrega documento da cotação segundo o nome da coleção; não há evidência de que seja parecer da análise nem de disponibilidade em recusas | GET `/fianca/credito/{cpf}/{numeroProposta}/parecer`, via `getCreditOpinion`, é documentado como PDF do parecer de crédito; disponibilidade para ambos os resultados ainda não está explicitada |

### Requisito do PDF do resultado

O PDF deve representar a decisão da análise, inclusive quando recusada. PDF da cotação, minuta, proposta ou apólice não comprova atendimento a esse requisito. `TooService::getQuotePdf` também não deve ser tratado como equivalente a `getCreditOpinion`.

Para cada companhia, confirmar endpoint, disponibilidade nos estados aprovado e recusado, conteúdo da decisão e formato de entrega (bytes, URL ou base64). Não condicionar a obtenção do parecer à existência de preço/cotação aprovada. Se a companhia não fornecer esse documento em algum estado, registrar a limitação; geração de relatório próprio pelo sistema permanece uma decisão pendente, sem implementação nesta etapa.

## Inventário documental completo — referência

As contagens e tabelas completas abaixo preservam o levantamento dos arquivos recebidos. Não representam uma lista de operações a implementar; o escopo ativo é o das cinco capacidades acima.

| Companhia | Operações do produto | Autenticação | Cobertura do service |
| --- | --- | --- | --- |
| Pottencial | 11 | 1 operação compartilhada | Criação e consulta de cotação |
| Too | 13 | 1 operação | Análise e parecer coincidem com o YAML; outras rotas do service não constam nele |

Na Too, o fluxo do YAML começa pela cotação e depois registra proposta vinculada à cotação. O código atual começa pela ficha, solicita análise e cota após aprovação. Isso indica contratos/fluxos distintos; não prova que o código esteja errado ou que as rotas ausentes não existam.

## Pottencial

As subseções históricas abaixo descrevem a coleção antiga. A revisão de 23/10, ao final desta seção, incorpora o contrato compartilhado por CPF/CNPJ e prevalece sobre lacunas antigas agora documentadas (atualização, reanálise, cartas e simulações de pagamento).

### Acesso

- Base referenciada pela coleção: `{{pottencial_api_url}}`. O padrão de `services.pottencial.base_url` é `https://api-hml.pottencial.com.br`; o ambiente pode sobrescrevê-lo.
- `POST /oauth/v3/access-token`: Basic Auth com `client_id` e `client_secret`; sem body na requisição da coleção.
- Operações do produto enviam headers `client_id` e `access_token`. Não confundir com Bearer da Too. Manter o mesmo contrato para CPF/CNPJ.
- O service armazena `access_token` em cache por 55 minutos; não calcula esse prazo a partir de `expires_in`.

### Endpoints

Prefixo de todas as rotas abaixo: `/insurance/v1/fianca-locaticia-mensalizado-pf`.
Os placeholders foram normalizados para `{id}`; a coleção mistura `{{quote_id}}` com `:quote_id`.

| Método | Sufixo | Finalidade / entrada | Service atual |
| --- | --- | --- | --- |
| POST | `/quotes` | Solicitar cotação; JSON descrito abaixo | `createRentalGuaranteeQuote` |
| GET | `/quotes/{quote_id}` | Consultar cotação | `getRentalGuaranteeQuote` |
| GET | `/quotes/facial-biometrics/{quote_id}` | Consultar URL de biometria facial; nome original contém “Diometria” | Ausente |
| GET | `/quotes/{quote_id}/draft` | Consultar documento da cotação | Ausente |
| POST | `/proposals` | Enviar proposta com `quoteId`, vigência e `payment` | Ausente |
| POST | `/proposals/{proposal_id}/accept` | Aceitar proposta; sem body no exemplo | Ausente |
| GET | `/proposals/{proposal_id}` | Consultar proposta | Ausente |
| GET | `/proposals/{proposal_id}/document` | Consultar PDF da proposta | Ausente |
| POST | `/policies` | Emitir apólice com `proposalId` | Ausente |
| GET | `/policies/{policy_id}` | Consultar apólice | Ausente |
| GET | `/policies/{policy_id}/document` | Consultar documento da apólice | Ausente |

Fluxo amplo inferido dos nomes, corpos e scripts da coleção, apenas como referência: autenticar → cotar → consultar cotação/biometria/documento conforme necessário → enviar proposta → aceitar → emitir apólice → consultar apólice/documento. As etapas de contratação e apólice não integram o escopo do sistema. A coleção não estabelece quando a biometria é obrigatória, nem todas as precondições das transições.

Os scripts Postman extraem `quoteId`, `proposalId` e `policyId` das respostas de criação. Nenhuma das 11 operações possui resposta de exemplo salva; códigos de status, schemas de retorno e erros não estão definidos.

### Estrutura observada da cotação

Os campos abaixo aparecem no exemplo; a coleção não define formalmente quais são obrigatórios.

- Raiz: `policyPeriodStart`, `policyPeriodEnd`, `policyType`, `discountPercentage`, `commercialLoadingFee`, `commissionedAgents[]`, `participants[]`, `riskObjects[]`.
- `commissionedAgents[]`: `documentNumber`, `role`, `commissionPercentage`, `lead` em parte dos agentes. Papéis exemplificados: `Broker`, `PolicyOwner`.
- `participants[]`: `documentNumber`, `role`, `participationPercentage` em um participante; endereço e contato em outro. Papéis exemplificados: `Beneficiary`, `PolicyHolder`, `Insured`.
- Endereço: `street`, `number`, `district`, `city`, `state`, `zipCode`, `complement`, `country`, `type`. Contato: `name`, `email`, `cellPhoneNumber`, `phoneNumber`.
- `riskObjects[]`: `type`, `tenantDocumentNumber`, `startLeaseContract`, `endLeaseContract`, `coverages[]`, `expenses[]`, `planKey`, `multiple`, `occupation`, `inhabited`, `riskLocation`, `paymentConditions`.
- `coverages[]`: `key`, `insuredAmount`; exemplo de chave `basica`.
- `expenses[]`: `description`, `value`; descrições exemplificadas: `VALOR_ALUGUEL`, `VALOR_CONDOMINIO`, `VALOR_IPTU`, `VALOR_GAS`, `VALOR_AGUA`, `VALOR_LUZ`.
- `riskLocation`: `nationalCoverage`, `address`. `paymentConditions`: `paymentType`, `installments`.
- Valores exemplificados, sem equivaler a catálogo de enums: `policyType=Unique`, `type=RentalProperty`, `planKey=Complete`, `occupation=residencial`, `paymentType=Invoice`.

Proposta: `quoteId`, `policyPeriodStart`, `policyPeriodEnd`, `payment.paymentType`, `payment.firstInstallmentDueDateDelay`, `payment.paymentInstructions`. Emissão: `proposalId`.

O endpoint de cotação é configurável por `services.pottencial.rental_endpoint`; o padrão coincide com a coleção. Os padrões de configuração `Unico`, `Boleto` e `traditional` diferem dos valores do exemplo acima. Isso demanda conferir a transformação do payload e o catálogo aceito, não substituir valores automaticamente.

### Revisão Pottencial — contrato compartilhado por PF e PJ — 23/10/2026

A decisão mais recente do usuário substitui a escolha anterior de uma API exclusiva PJ. Utilizar o contrato que aceita **CPF ou CNPJ** em `participants[].documentNumber` e `tenantDocumentNumber`, com `riskObjects[].occupation = Residencial` ou `Commercial`. Tipo de pessoa e finalidade da locação são independentes: atender PF/PJ residencial/comercial sem uma integração PJ separada.

O arquivo exclusivo PJ foi removido do projeto por solicitação do usuário. Suas rotas, autenticação, payloads, callbacks e enums deixam de orientar a implementação. A coleção histórica permanece preservada.

Fonte mantida: [Fiança Locatícia — CPF/CNPJ](pottencial-—-fiança-locatícia-(aluguel).json), OpenAPI 3.0.3 em JSON, versão 1.0.0, importável no Postman. Contém 18 operações, 16 paths e 36 schemas. SHA-256 preservado: `1037502D2AF6361FF08E9DF1BBFCB9F2BA65197FBC82DC6CACAE26F643FECA93`.

#### Autenticação e identificação

Homologação: `https://api-hml.pottencial.com.br`; produção: `https://api.pottencial.com.br`. Headers obrigatórios documentados: `client_id` e `access_token`. A coleção histórica obtém token via POST `/oauth/v3/access-token` com Basic Auth; o OpenAPI mantido não inclui emissão de token. O array global security declara alternativas OR, embora parâmetros explícitos exijam ambos os headers; preservar ambos.

Preservar `quoteId`/`quote_id` remoto por rodada, distinto de `quoteNumber`. Não haverá autenticação separada apenas porque o documento é CNPJ.

#### Inventário do contrato mantido

Abreviações somente nesta tabela: **F** = `/insurance/v1/fianca-locaticia`; **M** = `/insurance/v1/fianca-locaticia-mensalizado-pf`.

| Método e rota | Entrada / retorno / efeito |
| --- | --- |
| POST F`/quotes` | `FiancaLocaticiaQuote`; 200 com cotação, status e valores; 200 não é aprovação automática |
| POST F`/quotes/renew-policy` | `renewalPolicyNumber` obrigatório; 200 com nova cotação de renovação |
| GET F`/quotes/{quote_id}` | UUID; 200 com `FiancaLocaticiaQuote`; 404 possível |
| PUT F`/quotes/{quote_id}` | Cotação no body; 200 com cotação atualizada; não é PATCH nem prova de que qualquer campo/estado seja alterável |
| GET F`/quotes/{quote_id}/draft` | 200 `application/pdf`, binário: minuta, distinta de carta de decisão |
| GET F`/quotes/{quote_id}/letters` | Carta de aprovação/recusa; 200 JSON de objeto sem propriedades documentadas; representação do documento ainda indeterminada |
| GET M`/quotes/facial-biometrics/{quote_id}` | JSON `url`, `expiresAt`; documento não estabelece quando é obrigatória |
| POST F`/quotes/reanalysis` | `quoteId` obrigatório, `observation`, anexos `name`/`content` Base64; descrita como reanálise de cotação recusada; 200 com cotação |
| GET M`/quotes/{quoteId}/payment-simulations` | Array de formas e simulações de parcelas; erro de nome do parâmetro no OpenAPI, detalhado abaixo |
| GET `/riskobject/v1/products/fianca-locaticia/plans` | Array com `name`, `coverages[].name`, `coverages[].multiples` (strings), `expense`; não há paginação documentada |
| POST F`/proposals` | `ProposalV1`: `quoteId` e `payment` obrigatórios; 200 com proposta; fora do orçamento |
| POST F`/proposals/{proposal_id}/accept` | Sem body declarado; 200; aceitação, fora do orçamento |
| GET F`/proposals/{proposal_id}` | 200 `ProposalV1`; consulta de proposta |
| GET F`/proposals/{proposal_id}/document` | 200 PDF binário da proposta |
| GET `/policiesmanagement/v1/policies` | Filtros `ProductKey` e `EligibleForRenewal`; se este for true, ProductKey obrigatório; `data` + `pagination` na resposta; parâmetros de paginação não declarados |
| POST `/policiesmanagement/v1/policies` | `Policy`, `proposalId` obrigatório; 200 com apólice emitida; fora do orçamento |
| GET F`/policies/{policy_id}` | 200 `Policy`; consulta de apólice |
| GET F`/policies/{policy_id}/document` | 200 PDF binário da apólice |

Erros PF: referências a `400`, `401`, `500`; consultas/documentos incluem `404` conforme operação; planos e simulação também `403`. Erros estruturados usam `errors[].code/message`. Não classificar recusa de crédito por HTTP. Existem schemas de cancelamento sem operação de cancelamento em `paths`: não criar rota por inferência. Renovação, proposta, aceitação e emissão foram estudadas, mas não serão acionadas para apenas analisar e comparar preços.

#### Payload e estados — CPF/CNPJ

- `QuoteBase.required`: `policyType`, `commissionedAgents`, `participants`, `riskObjects`; o schema específico acrescenta `riskObjects` por `allOf`. Valores calculados como `quoteId`, `quoteNumber`, `createdAt`, prêmios e IOF não devem ser fabricados na entrada.
- `policyType`: `Unique` ou `ItemByItem`. Há contradição entre `required` e descrição que promete default Unique quando omitido; enviar valor explícito na futura montagem.
- Participantes exigem `documentNumber` e `role`; papéis `PolicyHolder`, `Insured`, `Beneficiary`. Documentos são strings de dígitos preservando zeros; retornos podem ofuscá-los. Beneficiários: participação entre 0.01 e 1.00, soma 1. O texto restringe `main` a Beneficiary, mas o exemplo usa no PolicyHolder: não copiar essa contradição sem confirmação.
- Agentes: `Broker`, `PolicyOwner`, `InsuranceAgent`; documentos/papéis obrigatórios, comissão com até 3 casas decimais, participação de co-corretagem somando 1. O exemplo de comissão é 0.15; representação fracionária dos 15% definidos para este contrato compartilhado.
- Raiz inclui vigências `date`, `paymentConditions`, desconto e agravo mutuamente exclusivos. O exemplo envia ambos zero; confirmar a interpretação de zero versus ausência.
- Risco exige `startLeaseContract`, `endLeaseContract`, `tenantDocumentNumber`, `expenses`, `planKey`, `multiple`, `occupation`, `inhabited`, `riskLocation`. `planKey`: `Basic`, `Complete`, `Traditional`; `multiple`: 12, 15, 18, 24, 30, 45; `occupation`: **`Residencial` ou `Commercial`**, com essas grafias.
- `multiple` é múltiplo do aluguel para importância segurada, não número de parcelas nem duração automática do contrato. `Coverage.indemnityPeriod` é descrito em **dias**, não confundir com meses da Too.
- Endereço requer street/number/district/city/state/zipCode; UF `state` com 2 caracteres. Despesas: aluguel, condomínio, IPTU, gás, água e luz, nos códigos `VALOR_*` já registrados no histórico. Cobertura exige `key`; limites e prêmios são campos separados.
- `QuoteStatus`: `Creating`, `UnderAnalysis`, `Denied`, `Approved`. Apenas Approved comprova aprovação; Creating/UnderAnalysis devem permanecer não terminais. `commercialPremium` inclui comissão/pró-labore; `grossPremium` é total com IOF e encargos; não representar como parcela.
- Propostas têm estados `Pending`, `Ready`, `Issued`, `Accepted`; apólices têm seus próprios estados. Não misturar esses catálogos com decisão de crédito.

#### Pagamentos e comparação de uma parcela em 30x

GET `/insurance/v1/fianca-locaticia-mensalizado-pf/quotes/{quoteId}/payment-simulations` retorna array de `PaymentSimulationsResponse`. Cada item tem `paymentType`, `firstInstallmentPaymentType`, `minFirstPaymentDate`, `maxFirstPaymentDate` e `installments[]`. Cada alternativa contém:

- `countInstallments`: quantidade a selecionar, **30**;
- `commercialAmount`, `iofAmount`, `grossAmount`, `additionalAmount`, `financialChargesAmount`: valores da condição, distintos de parcela;
- **`installments[]` interno: array de números com os valores das parcelas**.

Preservar a diferença entre os dois níveis chamados installments. Conferir quantidade e valores internos, primeira parcela/meio de pagamento e datas. O usuário confirmou que as parcelas de cada alternativa são iguais em todas as companhias; usar o valor comum retornado e conferir a consistência dos elementos, sem médias ou ajustes. Não dividir grossAmount por 30 nem somar IOF/encargos novamente: o usuário confirmou que o valor da parcela já os inclui.

**Regra para PF e PJ:** sem imobiliária, usar `Boleto`; com imobiliária, `Invoice`, conforme elegibilidade da cotação. Não há crédito na Pottencial. Selecionar **30 meses = 30 parcelas mensais**, sem entrada, com comissão de **15%**. Falta da condição ou falha no GET impede comparar o preço, mas não transforma aprovação em recusa. Repetir a consulta usando a cotação existente, sem criar outra cotação.

#### Contrato único e compatibilidade das rotas

O usuário confirmou suporte a CNPJ no POST `/insurance/v1/fianca-locaticia/quotes`. Esse contrato é a referência compartilhada por PF e PJ; a escolha anterior de uma família PJ separada foi revogada.

**Esclarecimento do usuário em 26/10/2026:** ambas as rotas funcionaram em testes anteriores. Essa informação encerra a compatibilidade como impedimento para iniciar a refatoração. Preservar a rota atualmente usada pelo sistema, `/insurance/v1/fianca-locaticia-mensalizado-pf`, e manter `/insurance/v1/fianca-locaticia` como alternativa documentada. Não trocar a rota atual nesta etapa nem descartar a alternativa. A condição comercial permanece mensalizada e o contrato segue compartilhado por CPF/CNPJ.

Na futura implementação, aproveitar a configuração existente (`services.pottencial.rental_endpoint`) para facilitar a seleção explícita da rota, sem espalhar caminhos por builders, providers e jobs. Respeitar os caminhos documentados de cada operação: o simulador permanece em `/insurance/v1/fianca-locaticia-mensalizado-pf/quotes/{quoteId}/payment-simulations`, e a carta é documentada em `/insurance/v1/fianca-locaticia/quotes/{quote_id}/letters`. Não substituir globalmente prefixos em operações cuja rota alternativa não tenha sido verificada.

Durante o desenvolvimento, testar as duas rotas quando possível, comparando payload aceito, status, identificadores, respostas e funcionamento das consultas, pagamentos e documentos. Registrar diferenças por operação e preservar a rota utilizada em cada cotação/rodada para diagnóstico e recuperação. A evidência de funcionamento anterior foi fornecida pelo usuário; esta atualização não executa novos testes remotos nem afirma equivalência irrestrita de todos os cenários.

A alternativa deve ser selecionável de forma controlada; não repetir automaticamente um POST em outra rota após erro ou timeout, pois a primeira tentativa pode ter criado a cotação. Monitorar falhas sem duplicar orçamentos e usar os IDs conhecidos nas consultas de recuperação. Nenhuma configuração ou rota de execução foi alterada nesta revisão documental.

#### Agentes comissionados — obrigatoriedade e opcionais — 26/10/2026

Fontes: prints de `commissionedAgents` e arquivo `C:\Users\Usuario\Downloads\Solicitar-cotacao-payload-pottencial.txt`. O arquivo foi lido e validado como JSON sintático; seus valores são placeholders de tipos, não um payload pronto ou um conjunto de defaults. Exemplos como `lead: false`, comissão zero e `installments: 0` não definem regras de negócio. Quantidade zero, por exemplo, não satisfaz o mínimo 1 documentado para parcelas. O modelo também inclui identificadores, status, datas de criação e valores calculados: sua presença não autoriza fabricá-los na criação.

**Regra confirmada:** enviar pelo menos um item em `commissionedAgents`, sempre incluindo o corretor com `role = Broker`. Dentro de cada agente, os únicos campos obrigatórios informados são `documentNumber` e `role`. Os opcionais escolhidos, após o esclarecimento sobre os formulários, são **`commissionPercentage`, `lead` e `participationPercentage`**, exclusivamente no corretor. Não estender a dispensa de opcionais desse objeto aos demais objetos da cotação sem analisar seus próprios requisitos.

| Campo | Tipo / contrato | Regra de envio |
| --- | --- | --- |
| `documentNumber` | string obrigatória; CPF/CNPJ somente com dígitos, preservando zeros à esquerda | Documento do respectivo corretor ou imobiliária; nunca converter em inteiro. O retorno pode ofuscar o documento |
| `role` | string obrigatória; `Broker`, `PolicyOwner`, `InsuranceAgent` | `Broker` para corretor; `PolicyOwner` para imobiliária quando aplicável. O enum InsuranceAgent existe, mas não foi escolhido para este fluxo |
| `commissionPercentage` | number opcional; até 3 casas decimais; comissão da corretora/pró-labore do estipulante | Enviar somente em Broker, mantendo o padrão de 15% e a representação `0.15` registrada para o contrato Pottencial |
| `lead` | boolean opcional; identifica a corretora principal; documentação permite omitir com uma única corretora | Enviar `true` somente em Broker, nos três formulários, conforme confirmação expressa do usuário; não confundir com o model Lead |

**PolicyOwner (imobiliária):** enviar somente `documentNumber` e `role`; omitir `commissionPercentage` e `lead` completamente, não enviar zero, false ou null em substituição. Não duplicar os 15% do corretor na imobiliária. A distribuição da comissão deixa de ser pendência: neste fluxo ela será informada apenas no Broker.

**Opcionais descartados em commissionedAgents:** `commissionAmount`, `isPayer` e `address` aparecem no modelo, mas não serão enviados neste escopo. A exclusão anterior de participationPercentage foi substituída pela confirmação de enviar 1 no Broker nos três formulários. Não copiar o exemplo inteiro nem presumir co-corretagem. Se outro cenário vier a exigir esses campos, revisar explicitamente suas regras antes de ampliar o payload.

O relacionamento por `PolicyOwner` é a representação da imobiliária definida pelo usuário para este fluxo. A matriz por formulário/vínculo e os valores de lead e participationPercentage foram confirmados na seção abaixo. Preservar as regras de pagamento já aprovadas e os agentes da mesma rodada nas três cotações, variando apenas planKey.

**Impacto futuro:** ajustar o builder para remover comissão e lead de PolicyOwner, preservar os campos selecionados do Broker e validar obrigatórios/tipos. Não alterar código nesta revisão. Prints e placeholders não substituem a futura conferência das respostas reais.

#### Matriz definitiva do payload por formulário — 26/10/2026

Fontes: orientação do usuário, prints dos enums de `participants.role` e `commissionedAgents.role`, leitura das três views de formulário e confirmação expressa dos valores do Broker. As views usam o mesmo form-shell com perfis `registered`, `unregistered` e `tenant`; isso não altera a necessidade de mapear os dados conforme a origem persistida e o vínculo real do lead.

| Formulário / origem | `commissionedAgents` | `participants` | `paymentConditions` |
| --- | --- | --- | --- |
| `registered-company.blade.php` — imobiliária cadastrada vinculada | Broker + PolicyOwner com documento da imobiliária vinculada | Um PolicyHolder com documento do lead/locatário | `paymentType = Invoice`, `installments = 30` |
| `unregistered-company_landlord.blade.php` — imobiliária não cadastrada ou proprietário | Somente Broker; sem PolicyOwner | Um PolicyHolder com documento do lead/locatário | `paymentType = Boleto`, `installments = 30` |
| `tenant.blade.php` — locatário | Somente Broker; sem PolicyOwner | Um PolicyHolder com documento do lead/locatário | `paymentType = Boleto`, `installments = 30` |

**Broker nos três formulários — valores confirmados pelo usuário:** enviar `documentNumber` do corretor, `role = Broker`, `lead = true`, `participationPercentage = 1` e `commissionPercentage = 0.15`. São números JSON para participação/comissão e booleano JSON para lead. Participação 1 significa 100% da participação na comissão; comissão 0.15 representa os 15% definidos. Não enviar 0.15 em participationPercentage nem interpretar lead como dados do solicitante. O contrato descreve participação na comissão em co-corretagem, cuja soma entre corretoras deve ser 1; com um Broker, o valor confirmado é 1.

**PolicyOwner:** somente no formulário de imobiliária cadastrada vinculada. Enviar `documentNumber` da imobiliária e **`role = PolicyOwner`**. A menção do usuário a “apenas documentNumber” significa não acrescentar opcionais: role continua obrigatório, como mostram as prints. Omitir lead, participationPercentage, commissionPercentage, commissionAmount, isPayer e address nesse agente. Não enviar os 15% para a imobiliária nem criar outro Broker para ela.

**Participants:** em todos os formulários, enviar somente `documentNumber` e `role = PolicyHolder` para o lead/locatário que está sendo analisado. Omitir os opcionais `participationPercentage`, `address`, `contact`, `isPayer` e `main` desse objeto. Não criar Beneficiary ou Insured neste fluxo. Os enums de participants (`Beneficiary`, `Insured`, `PolicyHolder`) não são os enums dos agentes (`Broker`, `PolicyOwner`, `InsuranceAgent`); não colocar PolicyOwner dentro de participants.

**Quem preencheu versus quem será analisado:** no formulário não cadastrado, a imobiliária ou proprietário responsável pelo preenchimento fica no cadastro local para contato do cliente. Não enviar seus dados como agente, participante, proprietário ou substituto do locatário no payload Pottencial. PolicyHolder é o lead/locatário analisado, não automaticamente a pessoa que preencheu o formulário. A mesma estrutura vale para o formulário do locatário. Preservar documentos como strings de dígitos; em PJ, usar o CNPJ do locatário empresarial conforme o contrato compartilhado, sem trocar pelo CPF do responsável.

**RiskObjects:** continuar enviando os dados do risco/locação do lead conforme os campos obrigatórios e regras já estudadas, inclusive tenantDocumentNumber coerente com o locatário de participants, imóvel, despesas, datas e planKey. A limitação a dois campos em participants não elimina os dados obrigatórios de riskObjects. Não inserir os dados de contato da imobiliária não cadastrada ou proprietário em outro objeto para contornar a regra de omissão.

Aplicar essa mesma matriz às três cotações Basic, Complete e Traditional da rodada, alterando somente planKey entre requisições. Coberturas e preços retornados continuam específicos de cada plano. Mantêm-se a regra fixa Sem entrada, comparação dos aprovados e documentos por plano. Se o cenário de imobiliária cadastrada não tiver documento/vínculo válido, sinalizar dado obrigatório ausente antes do envio; não fabricar PolicyOwner nem mudar silenciosamente a origem/pagamento.

**Pendências encerradas por esta definição:** representação da imobiliária cadastrada como PolicyOwner e seleção Invoice/Boleto por origem; campos e valores de lead/participação/comissão do Broker; omissão de comissão na imobiliária e de dados do responsável pelo preenchimento nos demais formulários. A validação remota desses cenários será técnica, sem reabrir as regras aprovadas.

Esta revisão altera somente o estudo. A futura refatoração deverá alinhar builder e validação à matriz, sem alterar formulários ou persistência nesta etapa.

#### Comparação com a implementação e pendências

- `Lead::rentalApplicantDocument()` prioriza CNPJ de lead_empresa; `rentalApplicantCpf()` representa o responsável. Preservar ambos sem substituir empresa pelo responsável. Manter uma companhia Pottencial por lead/rodada, com três cotações por plano conforme revisão de 26/10, sem provider ou builder exclusivo PJ.
- **Implementado em 29/10:** service consulta simulações e novas rodadas comparam a parcela confirmada; registros legados mantêm tratamento próprio. Falta homologação remota. Preservar quantidade 30, parcelas, moeda, vigência, cobertura, forma e encargos; não fabricar parcela dividindo um total.
- **Implementado em 29/10:** `Creating` está mapeado como processamento, como `UnderAnalysis`. Validar o fluxo com resposta real.
- Conferir `policyType` (`Unique`/`ItemByItem`), planos (`Basic`/`Complete`/`Traditional`) e `occupation` frente aos enums e fallbacks atuais.
- O exemplo antigo coloca pagamento dentro de riskObjects; builder e novo schema usam `paymentConditions` na raiz. `assistanceServices` do builder não aparece no novo schema; ausência documental não comprova rejeição.
- Alinhar comissão a 15% somente no Broker. O usuário definiu omitir commissionPercentage e lead de PolicyOwner; remover a replicação observada no builder na futura refatoração. Nos três formulários, Broker usa lead = true e participationPercentage = 1, além da comissão 0.15.
- Aplicar a matriz confirmada: imobiliária cadastrada vinculada como PolicyOwner + Broker e Invoice; demais origens somente Broker e Boleto. Validar o documento da imobiliária vinculada antes do envio.
- Obter retorno real de simulação para conferir as 30 parcelas, seus valores e primeiro vencimento. A inclusão de encargos e a condição fixa Sem entrada foram confirmadas pelo usuário.
- Resolver a interpolação de payment-simulations: path declara `{quoteId}`, mas o parâmetro referenciado se chama `quote_id`. Preservar fonte.
- Resolver divergências entre texto e exemplos de `main`, default de `policyType` e desconto/agravo.
- Minuta é PDF binário; `letters` é JSON genérico sem representação definida. Confirmar retorno e regras de retry/idempotência; não repetir mutações após timeout como consultas.
- PUT e reanálise dedicada estão documentados; sua existência não altera a decisão funcional de nova cotação após editar o lead.

A futura configuração continuará por seguradora, produto e condições, sem divisão de API por PF/PJ. Esta revisão documental não altera código, configuração ou banco, nem comprova homologação de todas as combinações. A integração separada PJ e suas pendências deixam de fazer parte do plano.

### Revisão Pottencial — simulador e três planos por lead — 26/10/2026

#### Fontes recebidas e entrada da cotação

Revisadas a print anterior de `paymentConditions`, as três prints do simulador e os arquivos `C:\Users\Usuario\Downloads\metudo-GET.txt` e `C:\Users\Usuario\Downloads\retorno-endpoit-simular-pagamento.txt`. O primeiro confirma a rota; o segundo é JSON válido com um modelo de resposta: strings ilustrativas, valores zero e lista de parcelas vazia. Não é evidência de preço real, disponibilidade em 30x ou ausência de entrada.

A print de Solicitar cotação confirma `paymentConditions` com `paymentType` obrigatório (`Invoice`, `Boleto`, `AutomaticDebit`) e `installments` inteiro obrigatório, mínimo 1. Usar `Invoice` com imobiliária e `Boleto` sem imobiliária, conforme regra vigente; solicitar **30** parcelas. Esse objeto configura a entrada; o preço para comparação será obtido do simulador, não calculado a partir do prêmio total da cotação. A print não define um indicador de entrada nem reduz automaticamente a quantidade para 29 como na Too.

#### Três cotações independentes e um resultado da companhia

**Correção mais recente do usuário em 26/10/2026:** a Pottencial trabalha **sempre sem entrada**, como regra fixa da companhia. Não é necessário descobrir um indicador equivalente ao comEntrada da Too, nem criar um campo de entrada não documentado. A primeira parcela e seu meio/vencimento não devem ser tratados como entrada. Essa regra não autoriza reduzir 30 para 29 parcelas na Pottencial.

**Planos e coberturas:** Basic, Complete e Traditional diferem em **coberturas e preço**; um plano pode oferecer mais coberturas que outro. Esta confirmação substitui expressamente a orientação anterior de que somente o preço mudava. Manter as três requisições variando planKey, sem atribuir artificialmente as mesmas coberturas aos retornos. Não inferir a hierarquia de cobertura ou preço apenas pelo nome: preservar os dados de cada plano. A regra de selecionar a menor parcela entre os aprovados elegíveis permanece; apresentar o plano e suas coberturas, sem afirmar que o mais barato oferece proteção equivalente ou superior.

**Decisão do usuário:** executar o mesmo fluxo para três cotações do mesmo lead/rodada, variando apenas `riskObjects[].planKey`. **Complemento de 28/10:** havendo cônjuge, aplicar esses três planos a cada cenário de pessoas da regra geral de cônjuges: duas individuais e uma conjunta somente após ambas serem recusadas. As três alternativas abaixo são por cenário, não um limite de três requisições para toda a rodada do casal:

| Nome de apresentação | Valor exato no contrato mantido |
| --- | --- |
| Básico | `Basic` |
| Completo | `Complete` |
| Tradicional | `Traditional` |

`planKey` aceita um único valor por risco, não uma lista. A grafia de integração é **Traditional**, conforme enum do JSON mantido; “Tradicional” é o nome usado na explicação do usuário. Não enviar `Tradicional` por tradução. Preservar os mesmos dados de lead, vigência, comissão e pagamento entre as três requisições; não combinar três planos em uma cotação nem sobrescrever o quoteId anterior ao cotar o plano seguinte.

Fluxo para **cada plano**, conforme instrução mais recente:

```text
Solicitar cotação → Consultar cotação
  → Approved: GET do simulador de pagamento → selecionar condição e parcela → disponibilizar PDF do plano
  → Denied: somente GET da carta de aprovação/recusa para o quoteId recusado; não chamar simulador
  → Creating/UnderAnalysis: manter pendente e continuar consulta controlada
  → Falha técnica: registrar falha da etapa, sem tratar como recusa
```

A consulta da carta para cada plano recusado é uma atualização expressa da orientação anterior que restringia toda busca de cartas à recusa unânime. Essa mudança se refere à obtenção do documento no fluxo Pottencial; não autoriza envio de e-mail de recusa global quando outra cotação estiver aprovada. Não buscar carta por ausência de preço nem por falha de rede. Falha da carta não altera a decisão de recusa recebida.

Preservar por plano: `planKey`, quoteId/quoteNumber, tentativa/rodada, payload enviado, status, respostas de consulta/simulação, condição selecionada, parcelas e referência de documento. Os três planos são alternativas da **mesma companhia**, não três seguradoras no lote. Uma única seção Pottencial deverá destacar o plano aprovado elegível com menor valor de parcela. Manter histórico e identificação dos outros planos para rastrear a escolha.

#### Downloads por plano e aprovação parcial — confirmação de 26/10/2026

A seção da Pottencial deve permitir baixar o documento de **cada plano**, não apenas o vencedor. Para aprovados, disponibilizar o PDF da cotação de cada plano; para recusados, consultar somente a carta de recusa, sem simular pagamento. Manter os documentos associados ao respectivo planKey, quoteId e rodada. Se houver apenas um plano aprovado, somente ele deve aparecer como oferta na seção Pottencial; eventuais cartas dos recusados são documentos de resultado, não ofertas com preço.

| Planos aprovados com parcela válida | Comportamento na seção Pottencial |
| --- | --- |
| 3 | Comparar os três, destacar a menor parcela e disponibilizar o PDF de cada aprovado |
| 2 | Comparar os dois, destacar a menor parcela e disponibilizar ambos os PDFs; a recusa do terceiro não bloqueia a comparação |
| 1 | Mostrar somente esse plano como oferta, com sua parcela e PDF; não exigir dois aprovados para exibir o resultado |
| 0 | Não apresentar oferta ou vencedor; se todos recusados, apresentar recusa e cartas correspondentes; falhas ou pendências não equivalem a recusa |

Planos recusados nunca entram no cálculo do menor preço. A obtenção da carta ou uma falha documental de outro plano não deve bloquear a comparação dos aprovados com preços válidos. Aprovação sem parcela disponível deve ser preservada como aprovação com preço indisponível, sem usar zero. Enquanto algum plano ainda aguarda decisão, os resultados disponíveis podem ser exibidos, mas o destaque deve indicar seu caráter provisório; uma recusa final não é uma pendência que impeça concluir o ranking.

A nova print confirma **GET `/insurance/v1/fianca-locaticia/quotes/{quote_id}/letters`**, UUID no path e headers `client_id` e `access_token`. A resposta 200 é documentada como `application/json`, objeto de carta de aprovação/recusa, sem campo de bytes/Base64/URL especificado. A mensagem “Failed to fetch / Make sure CORS is enabled” da interface documental não é uma resposta de recusa da cotação nem prova de indisponibilidade do endpoint para o servidor.

Para o PDF do aprovado, o contrato mantido documenta GET `/insurance/v1/fianca-locaticia/quotes/{quote_id}/draft` como minuta em `application/pdf`. Essa é a referência documental disponível para o download do aprovado; verificar se o documento corresponde ao PDF de cotação desejado e à condição exibida. A print de letters não confirma essa equivalência nem permite presumir Base64 como na Too. O fluxo de negócio está definido; a representação real de letters e o conteúdo do documento aprovado continuam sujeitos à conferência técnica.

Não emitir proposta/apólice para obter esses documentos. A seleção do vencedor não elimina as demais cotações nem seus PDFs. Recuperar falhas de documento isoladamente, sem recriar cotação, repetir análise de crédito ou alterar o resultado do plano.

#### Contrato do simulador e valor a comparar

GET `/insurance/v1/fianca-locaticia-mensalizado-pf/quotes/{quoteId}/payment-simulations`, com headers `client_id` e `access_token`; as prints também exemplificam `Accept` e `Content-Type` como `application/json`. Não há body documentado. A resposta 200 é JSON, array de opções por forma de pagamento. A documentação chama o parâmetro `quote_id`, mas o path usa `{quoteId}`: interpolar o UUID real, sem tratar os nomes como identificadores diferentes.

| Caminho dentro de cada opção | Interpretação |
| --- | --- |
| `paymentType` | Forma de pagamento da opção |
| `firstInstallmentPaymentType` | Forma da primeira parcela, podendo ser null; não comprova entrada ou ausência dela |
| `minFirstPaymentDate`, `maxFirstPaymentDate` | Limites para o primeiro vencimento; não são valores de parcela |
| `installments[]` | Alternativas de parcelamento |
| `installments[].countInstallments` | Quantidade da alternativa; selecionar 30 conforme padrão Pottencial |
| `installments[].installments[]` | Array numérico com os valores individuais das parcelas: fonte do preço a comparar |
| `commercialAmount`, `iofAmount`, `grossAmount`, `additionalAmount`, `financialChargesAmount` na alternativa | Totais e discriminações; preservar separadamente |

Conferir a forma de pagamento e a alternativa de 30 parcelas, validar quantidade e valores recebidos. As parcelas de cada alternativa são iguais conforme confirmação geral do usuário; usar esse valor final como preço principal. IOF, juros e demais encargos já estão incluídos conforme confirmação geral do usuário: não somá-los novamente, nem dividir grossAmount por 30. Preservar a sequência recebida e verificar sua consistência com a regra de parcelas iguais; divergência é inconsistência técnica, não uma modalidade a ser comparada por média ou menor elemento.

Valores zero e array vazio do TXT são placeholders, não gratuidade nem cotação aprovada sem custo. A redução de 30 para 29 confirmada na Too não foi estendida à Pottencial.

#### Seleção do menor preço e consequências para a futura refatoração

1. Comparar primeiro as alternativas elegíveis dos três planos Pottencial e identificar o menor valor por parcela. O plano selecionado deve acompanhar o preço no card e no documento. Conforme correção posterior do usuário, os planos diferem em coberturas e preço; o destaque indica menor parcela, não equivalência de proteção.
2. Usar esse resultado selecionado na comparação entre companhias, com moeda, vigência e condições coerentes. Preservar a resposta, o quoteId e as coberturas específicas da cotação selecionada. Não associar o preço de um plano às coberturas de outro nem declarar equivalência automática entre planos ou companhias.
3. Comparar quando houver pelo menos dois planos aprovados com preço elegível; recusas dos demais não bloqueiam essa comparação. Se apenas um for aprovado, mostrar somente essa oferta com seu PDF. Não aguardar simulação de recusado nem a conclusão de download/carta para comparar os aprovados. Enquanto houver decisão pendente, não anunciar vencedor definitivo; falhas ou ausência de preço devem ser indicadas sem inventar valores.
4. Recusa total da Pottencial exige os três planos recusados. Mistura de recusa com falha ou pendência não é recusa unânime. Aprovação sem simulação disponível continua aprovação com preço indisponível. Em empate, registrar igualdade de preço e preservar as diferenças de cobertura; não inventar um critério de desempate por cobertura.
5. Recuperar consultas de status, simulações e cartas usando o quoteId do plano e a mesma rodada. Não recriar os três orçamentos para repetir um GET nem permitir resposta antiga sobrescrever uma nova rodada.
6. A persistência atual de um único quoteId por análise precisa ser adaptada para representar as três cotações como alternativas da companhia. A definição de tabelas/modelos/jobs será feita na etapa de implementação; esta revisão não cria migrations ou altera a execução.

**Pendências remanescentes:** representação efetiva da carta e confirmação do documento aprovado em /draft. A igualdade dos valores das parcelas em cada alternativa foi confirmada para todas as companhias; um retorno real do simulador servirá para validação técnica, não para decidir essa regra. A matriz por formulário/vínculo, PolicyOwner e valores do Broker foi confirmada na seção específica. Comissão definida: 15% somente no Broker; omitir comissão de PolicyOwner. A regra de três planos, a fonte do preço e o fluxo aprovado/recusado estão definidos. A inclusão de encargos na parcela e a regra fixa de sempre Sem entrada já foram confirmadas e não são pendências.

Somente estudo documental: JSON do retorno validado, rota e enum conferidos nas fontes; nenhuma chamada remota, emissão ou alteração de código realizada.

## Too Seguros

### Acesso

- O YAML declara `https://openapi-uat.tooseguros.com.br` como base; também é o padrão de `services.too.base_url`.
- `POST /authentication`: no YAML exige headers `clientid` e `clientsecret`, sem request body declarado e com `security: []`.
- O exemplo de resposta de autenticação possui `access_token`, `expires_in`, `token_type`, `scope`.
- As operações de negócio herdam Bearer (`Authorization: Bearer <token>`) e declaram `clientid` e `clientsecret`. Várias também exigem `Content-Type` como parâmetro de header.
- Divergência: `TooService::getAccessToken` envia Basic Auth e formulário `grant_type=client_credentials`, sem os headers de credenciais indicados para autenticação no YAML. Deve-se confirmar qual contrato de autenticação corresponde ao fluxo atual.
- Nas operações de negócio o service envia Bearer + `clientid` + `clientsecret`; token em cache fixo de 55 minutos.

### Endpoints do YAML

| Método | Caminho | Finalidade / entrada | Linha no YAML |
| --- | --- | --- | --- |
| POST | `/fianca/cotacao/aluguel-garantido` | Cotar; JSON `CriarCotaoAluguelGarantidoRequest`; descrito como PF residencial | 215 |
| GET | `/fianca/cotacao/{numeroCotacao}` | Consultar cotação | 310 |
| POST | `/fianca/proposta/{cpf}` | Registrar proposta; JSON `RegistrarPropostaRequest` | 371 |
| GET | `/fianca/proposta/{cpf}/{numeroProposta}` | Consultar status da ficha e proposta | 459 |
| POST | `/fianca/proposta/{numeroProposta}/proponentes/{cpf}/documentos` | Enviar documentos; formulário `anexo`, `tipoDocumento`, `descricao` | 520 |
| POST | `/fianca/credito/{cpf}/{numeroProposta}/analisar` | Solicitar análise; sem request body declarado | 601 |
| GET | `/fianca/credito/{cpf}/{numeroProposta}/parecer` | Consultar PDF do parecer de crédito | 662 |
| GET | `/fianca/proposta/formaspagamento/{numeroCotacao}` | Consultar formas de pagamento | 729 |
| PUT | `/fianca/proposta/{numeroProposta}/atualizar` | Atualizar proposta aprovada; JSON `AtualizarRegistrodePropostaAprovadaRequest` | 783 |
| GET | `/fianca/proposta/{cpf}/{numeroProposta}/pdf` | Consultar PDF da proposta | 898 |
| POST | `/fianca/proposta/{numeroProposta}/transmitir` | Transmitir proposta para emissão; sem request body declarado | 959 |
| GET | `/fianca/apolice/{numeroApolice}` | Consultar apólice | 1013 |
| GET | `/fianca/apolice/{numeroApolice}/pdf` | Consultar PDF da apólice | 1067 |

Fluxo documental amplo inferido, apenas como referência: autenticar → criar/consultar cotação → registrar proposta com `numeroCotacao` → enviar documentos conforme necessidade → solicitar análise e consultar status/parecer → consultar formas de pagamento → completar proposta aprovada → transmitir → consultar apólice. O recorte do sistema termina no orçamento, sua análise, atualização, resultado e PDF; completar contratação, transmitir e consultar apólice não fazem parte da integração pretendida.

### Payloads e respostas

Cotação: todos os 21 campos abaixo constam em `required` do schema:

`cep`, `cnpjCorretor`, `fimVigencia`, `finalidadeLocacao`, `indiceReajusteAluguel`, `inicioVigencia`, `percentualComissao`, `periodoIndAluguel`, `tipoPessoa`, `tipoSeguro`, `valorAgua`, `valorAluguel`, `valorCondominio`, `valorDanosAMoveis`, `valorDanosAoImovel`, `valorGas`, `valorIptu`, `valorLuz`, `valorMultasContratuais`, `valorPinturaExterna`, `valorPinturaInterna`.

- No YAML histórico, `percentualComissao` é `number` e o exemplo usa `0.18`. **Não aplicar essa escala ao endpoint atual `/fianca/proposta/cotacao`: o usuário confirmou `15.0` para 15% nesse endpoint.** Valores de coberturas e `periodoIndAluguel` estão tipados como inteiros. Não assumir suporte a centavos ou mudar escala com base apenas no exemplo.
- Exemplos de strings: `Residencial`, `Pessoa Fisica`, `Novo`, `IGPM`. Não há enum completo declarado.
- A resposta exemplificada de cotação contém `numeroCotacao`, `versaoCotacao`, `dataCriacao`, `request`, `response.coberturas`, `response.condicoesPagamento`, entre outros dados. Está declarada como `text/plain`, contendo exemplo de JSON em string.
- Proposta exige `numeroCotacao` (string), `pretendentes[]` e `locacao`. Pretendente inclui identificação, nascimento, estado civil, residência/responsabilidade financeira, rendas, emprego, profissão, CEP, RG, telefone e campos de pessoa politicamente exposta.
- Locação inclui endereço, imobiliária, periodicidade, vigências e `empresaConstituida`. Imobiliária exige `razaoSocial` e `cnpj`; este último foi tipado como inteiro no YAML, diferente de outros documentos tipados como string.
- Atualização da proposta exige `numeroCotacao`, `opcaoPagamento`, `formaPagamento`, `pretendentes[]`, `locacao`, `proprietario`. Pagamento exige `tipoPagamento`, `tipoEntrada`, `tipoOpcaoParcelamento`, `quantidadeParcelas`, todos inteiros, sem catálogo explicativo.
- No cadastro `locacao.empresaConstituida` é string; na atualização é objeto com `cnpj` e `ramoAtividade`, acompanhado de `possuiEmpresaConstituida`. Não reutilizar cegamente o mesmo DTO entre essas operações.
- Documentos: media type declarado `application/x-www-form-urlencoded`, campos string. O body é marcado como opcional, mas seus três campos são obrigatórios no schema. A codificação de `anexo` não é explicada; não presumir multipart, base64 ou upload binário.
- Todas as operações declaram `200`; parecer também declara `400` e `500`. A maioria não define schema de resposta. Operações chamadas PDF não especificam o media type de resposta, nem se entregam bytes, URL ou base64.

### Comparação com TooService

| Método do service | Método HTTP e caminho usados | Correspondência no YAML |
| --- | --- | --- |
| `registerProposalFicha` | POST `/fianca/proposta/ficha` | Não consta; YAML registra via POST `/fianca/proposta/{cpf}` com payload diferente |
| `submitCreditAnalysis` | POST `/fianca/credito/{cpf}/{numeroProposta}/analisar` | Coincide; service envia array vazio como JSON, YAML não declara body |
| `getProposalStatus` | GET `/fianca/proposta/v3/{cpf}/status/{numeroProposta}` | Não consta; YAML usa GET `/fianca/proposta/{cpf}/{numeroProposta}` |
| `updateProposalBasicData` | PUT `/fianca/proposta/ficha/{numeroFicha}/dados-basicos` | Não consta; atualização de proposta aprovada do YAML é outra operação |
| `getReanalysisReasons` | GET `/fianca/credito/motivos-reanalise` | Não consta |
| `submitReanalysis` | POST `/fianca/credito/{cpf}/{numeroProposta}/solicitar-reanalise` | Não consta |
| `requestQuote` | POST `/fianca/proposta/cotacao` | Não consta; YAML cota via POST `/fianca/cotacao/aluguel-garantido` |
| `getCreditOpinion` | GET `/fianca/credito/{cpf}/{numeroProposta}/parecer` | Coincide no caminho; representação PDF ainda precisa de confirmação |
| `getQuotePdf` | GET `/fianca/proposta/cotacao/{numeroCotacao}/pdf` no código atual | Ausente no YAML; print posterior do passo 6.1 exige **POST com body de pagamento**, correção pendente. PDF de proposta não é PDF de cotação |

O comentário de `getProposalStatus` também difere da implementação: o código inclui `/v3/`. Para este estudo foi considerado o caminho executado.

O builder atual gera ficha com `cnpjCorretor`, `razaoSocialCorretor`, `pretendentes`, `locacao`. A cotação atual recebe `numeroFicha`, `inicioVigenciaContratoLocacao`, `finalVigenciaContratoLocacao`, `indiceDeReajusteAluguel`, `periodoIndenitario`, `percentualComissao` e `coberturas`. Portanto, não basta trocar URLs para adotar o fluxo do YAML.

O provider faz ficha → análise → status → cotação quando o código de status é **8 ou 16**, conforme confirmação do usuário em 03/11/2026. A restrição anterior a apenas 8 era uma interpretação do código local, não um catálogo confirmado pelo YAML, e foi substituída pela regra abaixo.

### Confirmação Too — status 16 permite cotação — 03/11/2026

**Regra confirmada pelo usuário:** o status **16 (Crédito Pré-aprovado)** permite realizar a cotação, seguindo o mesmo fluxo do **8 (Crédito Aprovado)**. A pré-aprovação indica avaliação biométrica pendente; biometria não será implementada nesta versão. O objetivo é obter e apresentar os valores da cotação, não contratar, transmitir, emitir apólice ou garantir cobertura.

Ambos os códigos autorizam POST `/fianca/proposta/cotacao` com a ficha existente. Após sucesso, usar o mesmo tratamento de aprovação, consulta de pagamentos, seleção de preço e consolidação. Preservar o código e a descrição originais da Too, inclusive a pré-aprovação. Uma falha ao solicitar cotação segue o tratamento técnico já existente para o status 8; não produzir aprovação ou valores fictícios. Manter as proteções contra repetição da criação de cotação e tentativas obsoletas.

Continuam válidos período indenitário 30, `IGP_M`, comissão `15.0`, Fatura para imobiliária cadastrada e Boleto para demais origens, opção Mensal e Sem entrada. A quantidade efetiva e a parcela vêm da companhia: admitir 29 parcelas sem entrada no prazo-base 30, sem divisão local nem desconto adicional. O PDF continua com quantidade enviada 30 e os códigos da seleção existente. Esta decisão não altera payloads, endpoints, biometria ou emissão e não reprocessa automaticamente registros anteriores.

### Revisão Too — solicitar cotação e regras recebidas em 23/10/2026

**Fontes:** arquivo `C:\Users\Usuario\Downloads\solicitar-cotacao-regras-payload-too.txt`, print “Regras cotação” e esclarecimentos do usuário. O TXT é um JSON descritivo: seus textos indicam tipos e obrigatoriedade, não são valores para enviar. Ele complementa o contrato do endpoint em uso, não o schema histórico de `/fianca/cotacao/aluguel-garantido`.

**Fluxo conferido:** `TooInsuranceProvider::requestQuoteAfterApprovedStatus()` chama `TooRentalGuaranteePayloadBuilder::buildQuotePayload()` e `TooService::requestQuote()`, que executa **POST `/fianca/proposta/cotacao`**. A ficha deve existir e fornecer `numeroFicha`; no fluxo de análise, a cotação sucede o status que permite cotar. O provider extrai número da cotação, prêmio, condições de pagamento e coberturas. Essa extração não comprova seleção de parcela em 30x. O usuário informa que o endpoint funciona no sistema; esta revisão não realizou chamada remota.

#### Período indenitário e índice de reajuste

| Parâmetro | Contrato recebido | Decisão do sistema |
| --- | --- | --- |
| `periodoIndenitario` | Obrigatório, numérico, quantidade de meses. A print permite 12, 15, 18, 24 ou 30 meses | Enviar **30**, como número |
| `indiceDeReajusteAluguel` | Obrigatório, texto: `IPCA`, `SALMIN`, `INPC`, `MAIOR`, `IGP_DI`, `IGP_M`, `IP`, `ICV`, `TJSP`, `IVAR` | Enviar sempre **`IGP_M`**, com essa grafia |

**Regra confirmada pelo usuário:** 30 meses correspondem a 30 parcelas mensais, uma por mês, tanto na Too quanto na Pottencial. Na Too, manter `periodoIndenitario = 30` e obter no GET de pagamentos o valor da opção de 30 parcelas. Não há pendência sobre a periodicidade mensal desejada. A montagem deve respeitar os nomes e significados dos campos do contrato, e o preço deve vir do retorno da seguradora, sem divisão arbitrária do total por 30.

**Estado do código:** o builder lê `services.too.default_indemnity_period` com fallback 30 e `services.too.default_rent_adjustment_index` com fallback `IGP_M`. Porém, `config/services.php` define o padrão do índice como **`IPCA`**, por `TOO_DEFAULT_RENT_ADJUSTMENT_INDEX`; o período é configurável por `TOO_DEFAULT_INDEMNITY_PERIOD`. Portanto, os fallbacks não garantem os valores aprovados. Alinhar configuração e validação quando autorizado; nenhum ambiente ou cache foi alterado nesta revisão.

#### Demais regras do endpoint recebidas

- Obrigatórios na raiz: `cnpjCorretor`, `razaoSocialCorretor`, `numeroFicha` numérico, `inicioVigenciaContratoLocacao`, `finalVigenciaContratoLocacao`, índice e período. `percentualComissao` é opcional e decimal.
- O TXT exemplifica datas como `2021/12/10`; o builder serializa `Y-m-d`. Registrar a diferença para confirmação sem modificar um endpoint funcional apenas pelo exemplo.
- A print permite início do contrato retroativo **maior que 30 dias**; nesse cenário, o início do seguro é a data da transmissão. Não reinterpretar como limite máximo de 30 dias de retroatividade. Outros casos retroativos não estão detalhados.
- Início futuro do contrato: até 30 dias; nesse cenário, o seguro começa no início do contrato.
- Vigência do contrato: mínimo 4 e máximo 60 meses; início anterior ao término. O término do seguro deve coincidir com o término do contrato.
- “Data da transmissão” é expressão da fonte para cálculo de vigência; não autoriza transmitir/emitir proposta no fluxo de orçamento.
- Comissão: mínimo 10% e máximo 40%. **Escala confirmada posteriormente pelo usuário após consultar a documentação: `percentualComissao` recebe pontos percentuais decimais; enviar `15.0` para 15%**, como número JSON, não `0.15` nem texto com símbolo %. O campo é opcional no contrato, mas será preenchido pelo sistema com o padrão aprovado. O fallback 0.25 do builder é evidência do código anterior e deverá ser corrigido na implementação.
- Em `coberturas`, `valorAluguel` é obrigatório, decimal e mínimo 200; aluguel + encargos não pode ultrapassar 24999. Confirmar a composição dos encargos se necessário, sem incluir automaticamente todas as coberturas adicionais na soma.
- `valorAgua`, `valorCondominio`, `valorGas`, `valorIptu` e `valorLuz`: opcionais, decimais com ponto, mínimo 15 quando informados. O TXT não autoriza zero nesses campos apenas por serem opcionais.
- `valorDanosAoImovel`, `valorDanosAMoveis`, `valorMultasContratuais`, `valorPinturaExterna` e `valorPinturaInterna`: opcionais, decimais, iguais ao aluguel ou zero.

Estas são regras para a futura revisão de validação; documentá-las não significa que todas estejam implementadas. O fallback atual de término do contrato em 30 meses é independente de `periodoIndenitario`.

#### Pagamentos e configuração futura

**Registro anterior, refinado para a Too na revisão do passo 6.1 abaixo:** fatura só está disponível com imobiliária presente na cotação; **sem imobiliária preenchida, usar crédito**, exceto na Pottencial e na Too, para as quais o usuário definiu posteriormente boleto. Considerar os dados da imobiliária efetivamente informados à companhia, não apenas o tipo de solicitante ou um identificador interno. `cnpjCorretor` e `razaoSocialCorretor` identificam a corretora e não substituem imobiliária.

O TXT de solicitar cotação **não contém parâmetro de forma de pagamento nem quantidade de parcelas**. Não adicionar campos presumidos. A consulta GET e os códigos do exemplo estão detalhados na revisão de formas de pagamento abaixo; a decisão vigente Too adota Fatura para a origem imobiliaria_cadastrada e Boleto para as demais origens. A estrutura concreta será conferida no retorno real; usar a condição selecionada no PDF, sem contratação. A disponibilidade de 30x deve ser conferida no retorno.

A futura página de configuração de seguradoras no dashboard dos corretores permitirá administrar parâmetros por companhia **após concluir e validar o sistema de análise**. Nesta etapa, não criar tela, tabela, migration ou fluxo de configuração. Manter como decisões aprovadas `IGP_M` e período 30. As revisões deste estudo já incorporam a consulta Too e o contrato Pottencial compartilhado por PF/PJ. Os padrões fixos, incluindo Sem entrada, estão consolidados na seção de configuração futura.

**Verificação:** leitura do TXT, print e fluxo local; revisão do diff e integridade textual. Nenhum código de aplicação, configuração ou banco foi alterado; nenhuma cotação foi solicitada.

### Revisão Too — consulta de formas de pagamento e valor da parcela — 23/10/2026

**Fontes:** print “6 - Consultar forma de pagamento”; arquivo `C:\Users\Usuario\Downloads\retorno-consulta-forma-pagamento-Pottencial.txt`; operação de `openapi_final (1).yaml` iniciada na linha 729; esclarecimento posterior do usuário sobre suporte a 30 meses/parcelas. Apesar de “Pottencial” no nome do TXT, o usuário o apresentou como exemplo da **Too**, associado à URL Too da print. Não atribuir este contrato à Pottencial nem tratar o exemplo como cotação real atual.

**Esclarecimento que prevalece:** o usuário confirmou que a API Too permite **30 meses/parcelas**. Os valores 23/24 presentes no TXT são apenas condições do exemplo documental e **não representam o limite geral da API**. A print confirma explicitamente período indenitário de 30 meses; a confirmação de suporte a 30 parcelas foi fornecida pelo usuário. Conforme confirmação posterior, a condição de 30 meses significa uma parcela por mês, totalizando 30 parcelas. Enviar `periodoIndenitario = 30` e obter do retorno de pagamentos o valor dessa condição, sem deduzir o preço pelo total dividido por 30.

#### Chamada e correspondência com o OpenAPI

- Método: **GET**.
- URL de homologação da print e do YAML: `https://openapi-uat.tooseguros.com.br/fianca/proposta/formaspagamento/{numeroCotacao}`.
- `numeroCotacao` é parâmetro obrigatório no **caminho**, tipado como string no YAML. Utilizar o identificador recebido ao solicitar cotação, não `numeroFicha`, `numeroProposta` ou o ID local da análise.
- Headers: `Authorization: Bearer <token>`, `clientid`, `clientsecret` e `Content-Type: application/json`. A print escreve `ClientId`/`ClientSecret`; os nomes de headers HTTP não diferenciam maiúsculas de minúsculas. O YAML declara os três últimos headers como obrigatórios e herda a segurança Bearer global.
- Não há body ou parâmetros de query documentados para filtrar forma ou quantidade; consultar e selecionar as condições no retorno.
- Operação `ConsultadeFormasdePagamento`, resposta documentada `200`, sem schema ou exemplo de corpo nessa operação do YAML. O TXT complementa essa lacuna; não presumir que todas as cotações tenham exatamente as mesmas opções.
- Consulta de pagamento não é transmissão, contratação ou emissão. Não chamar endpoints de atualização/transmissão apenas para obter preço.

#### Estrutura do retorno e interpretação

| Caminho | Uso no estudo e futura integração |
| --- | --- |
| `codigoProduto`, `tipoSeguro`, `inicioVigencia`, `fimVigencia` | Contexto da oferta; preservar vigência e produto junto do preço |
| `cep`, `cnpj` | Dados de contexto do exemplo; não inferir pelo nome de quem é o documento nem usar esse campo isoladamente para comprovar imobiliária |
| `quantidadeParcelasPossiveis` | Quantidade geral informada; não substitui a condição efetivamente escolhida |
| `tipoPagamento[]` | Catálogo com `id`, `tipo`, `descricao`. Exemplo: tipo 1 = Boleto/Individual, tipo 2 = Fatura |
| `tipoEntrada[]` | Catálogo com `id`, `tipo`, `descricao`. Exemplo: tipo 1 = Com Entrada, tipo 2 = Sem Entrada |
| `opcoesParcelamento[]` | Grupos contendo `id`, `tipo`, `descricao`, `valorTotal`, `desconto`, `parcelas`, `comEntrada`, `observacoes` |
| `opcoesParcelamento[].parcelas[].numeroParcela` | No exemplo representa a quantidade da alternativa (2x, 3x etc.), não uma lista de vencimentos individuais |
| **`opcoesParcelamento[].parcelas[].valor`** | **Valor numérico da parcela da alternativa: candidato ao preço principal Too na comparação em 30x** |
| `opcoesParcelamento[].parcelas[].descricao` | Texto de apresentação, como `1 + 23 x R$20.00`; não extrair moeda ou quantidade por parsing desse texto quando existem campos numéricos |
| `comEntrada`, `observacoes` | Condições que precisam acompanhar o preço e podem impedir equivalência direta com outra oferta |

O TXT contém sete grupos: à vista; mensal sem/com entrada; até 4x sem/com entrada; até 36x sem/com entrada. Há 49 alternativas ao todo. Os mesmos IDs e tipos se repetem entre variantes com e sem entrada: **não deduplicar apenas por `id` ou `tipo`**, pois eliminaria condições distintas. O tipo 4 e o rótulo “Até 36x” não garantem uma quantidade específica; ler as alternativas. A quantidade 24 aparece como `1 + 23`, incluindo entrada, enquanto a condição sem entrada do exemplo chega a 23.

A observação da resposta atribui a redução de parcelas à adequação ao fim da vigência. Registrar como explicação fornecida pela API nesse exemplo, não como regra legal independentemente verificada, nem como impedimento geral a 30x. Esclarecimento posterior: para a Too sem entrada, a base de 30 meses resulta em 29 parcelas; selecionar a alternativa recebida, sem descontar novamente nem recalcular seu valor.

Os valores arredondados podem não recompor exatamente `valorTotal`: no exemplo, 23 × 20,87 = 480,01, enquanto o total informado é 480,00. Preservar total e parcela retornados, sem recalcular ou ajustar silenciosamente. O usuário confirmou posteriormente que o valor da parcela já inclui IOF/juros e demais encargos. Preservar descontos, observações e eventuais diferenças entre parcelas conforme retornados, sem ajuste local.

#### Seleção futura em 30x e regra de forma de pagamento

1. Consultar após obter `numeroCotacao`, vinculado à rodada atual. Manter `periodoIndenitario = 30` e `indiceDeReajusteAluguel = IGP_M` como decisões de entrada já aprovadas.
2. Aplicar a regra vigente por origem: **Fatura para imobiliaria_cadastrada e Boleto para todos os demais formulários na Too**. O exemplo identifica **Boleto/Individual = tipo 1** e **Fatura = tipo 2**. Manter fatura conforme elegibilidade; não procurar ou inventar código de crédito para executar o fluxo documentado. Isso é uma decisão de uso, não prova de inexistência de crédito em toda versão da API.
3. Consumir as opções efetivamente retornadas para exibição e comparação, sem construir combinações de catálogos nem enviar seleção para contratação. A conferência do retorno real determinará como as condições se relacionam; não atribuir a uma parcela um meio ou condição ausente do retorno.
4. Localizar a alternativa correspondente ao prazo-base de 30 meses: na Too Sem entrada, **29 parcelas**, conforme correção posterior; conservar grupo, forma, entrada, vigência, total, desconto e observações. Não escolher a menor parcela de outra quantidade nem fabricar uma opção ausente. Suporte da API a 30x não significa que toda cotação retornará essa condição.
5. Usar `parcelas[].valor` dessa alternativa como valor de parcela Too. A decisão posterior é **Sem entrada**: selecionar `comEntrada = false`, associado ao tipo de entrada 2 do catálogo recebido. Variantes com entrada não participam do ranking padrão; não há mais pendência de escolha entre com/sem entrada.
6. Se faltar condição elegível, indicar indisponibilidade para comparação; não transformar aprovação em recusa. Falha técnica do GET é diferente de consulta bem-sucedida sem opção de 30x e deve permitir recuperação independente da criação da cotação.

O schema `FormaPagamento` do YAML contém inteiros `tipoPagamento`, `tipoEntrada`, `tipoOpcaoParcelamento`, `quantidadeParcelas`, usado na atualização da proposta aprovada. Isso ajuda a distinguir os códigos numéricos dos UUIDs dos catálogos, mas não autoriza atualizar a proposta nesta etapa nem comprova todas as combinações. A consulta GET não exige enviar esse objeto.

#### Esclarecimentos posteriores para a refatoração Too

- **Origem do formulário — regra vigente Too:** imobiliária cadastrada usa Fatura; todos os demais formulários usam Boleto, conforme orientação posterior do usuário. Não condicionar a implementação à descoberta de um código de crédito.
- **Objetivo do GET:** capturar as condições retornadas, apresentá-las no projeto e utilizar o valor da parcela de **30 meses/30 parcelas mensais** para comparar o melhor negócio. Não implementar contratação nem transmissão. A seleção de pagamento será enviada somente à consulta do PDF (passo 6.1), conforme esclarecimento posterior, sem executar atualização para emissão. Não é necessário construir uma matriz de combinações possíveis entre os catálogos.
- **Entrada:** o usuário considera provável que o retorno real não traga simultaneamente Com entrada e Sem entrada, mas aguarda uma resposta para confirmar. Tratar isso como hipótese, não contrato garantido: o exemplo documental contém ambas as variantes. Preservar o padrão aprovado **Sem entrada**; se ambas vierem, selecionar a variante correspondente. Se a condição não puder ser identificada, não rotular silenciosamente como sem entrada. Não criar estruturas especulativas antes de verificar a resposta real.
- **Comissão — pendência encerrada:** usuário confirmou faixa **mínima de 10% e máxima de 40%** e, após consultar a documentação, confirmou a representação **`"percentualComissao": 15.0`** para o padrão de 15%. Usar número JSON em pontos percentuais; não converter para fração. Essa confirmação corresponde ao endpoint atual POST `/fianca/proposta/cotacao`, não ao schema histórico de outro endpoint.
- **Imobiliária — esclarecimento posterior:** o usuário identificou seu preenchimento na atualização para emissão (passo 7), fora desta versão. Para a Too, selecionar Fatura apenas na origem imobiliaria_cadastrada; todas as demais origens usam Boleto. O novo passo 6.1 gera o PDF sem executar o passo 7, conforme revisão específica abaixo.

Consequência para o plano: implementar a consulta e a leitura do retorno sem exigir uma etapa remota de seleção de pagamento; a disponibilidade de preço permanece separada da decisão de crédito. Capturar um retorno real autorizado para confirmar estrutura e entrada, aplicando a origem do formulário para selecionar o meio. O passo 7 de atualização para emissão permanece fora do fluxo; o passo 6.1 recebe a seleção para gerar o PDF. Esta atualização apenas consolida decisões no estudo; não implementa a refatoração.

#### Encaixe no sistema e próximos passos autorizáveis

Na leitura atual, `TooService` possui `requestQuote()` e o helper privado `getJson()`, mas **não possui método para consultar formas de pagamento**. O provider extrai `paymentConditions` da resposta da cotação e grava em `available_plans`/`quote_summary`; não executa este GET. A extração existente não deve ser confundida com o novo retorno detalhado.

Quando autorizado: adicionar a operação ao service reutilizando autenticação; consultar após cotação válida no provider; preservar retorno por `numeroCotacao` e `attempt_id`; normalizar a condição de 30x sem sobrescrever totais com parcelas; compartilhar a seleção entre card, comparação e PDF. Reutilizar a cotação existente em recuperação do GET, protegendo contra respostas de rodadas antigas. Consulta sem preços prontos deve ser visível sem apresentar vencedor definitivo prematuramente.

**Ordem definida pelo usuário:** aplicar esta consulta de pagamentos Too antes da integração Tokio; receber e estudar os dados de pagamento Pottencial para confirmar a mesma base de comparação. Nesta entrega, somente documentação: TXT validado como JSON, catálogos e alternativas conferidos programaticamente, trecho do OpenAPI e código lidos, nenhuma chamada remota ou alteração de código realizada.

### Revisão Too — PDF da cotação e pagamento por origem do formulário — 23/10/2026

Esta decisão posterior prevalece sobre referências anteriores à pendência de imobiliária na ficha e à seleção de pagamento Too apenas pela presença genérica de imobiliária. Fontes: explicação do usuário e prints dos passos **6.1 — Consultar PDF da cotação**, **7 — Atualizar dados da proposta forma de pagamento** e payload do PDF. O YAML versionado não contém a operação 6.1; não confundir com o GET de PDF da proposta que ele documenta.

#### Regra vigente de pagamento Too

| Origem do lead | Condição para exibição, comparação e PDF |
| --- | --- |
| Formulário de imobiliária cadastrada (`tipo_solicitante = imobiliaria_cadastrada`) | **Fatura**, `tipoPagamento = 2`, **29 parcelas sem entrada no prazo-base de 30 meses**, conforme correção posterior |
| Todos os demais formulários | **Boleto**, `tipoPagamento = 1`, **29 parcelas sem entrada no prazo-base de 30 meses**, conforme correção posterior |

Usar a origem persistida do formulário como critério. Uma imobiliária apenas informada em outro formulário não muda essa origem nem autoriza selecionar fatura na Too. Esta precisão é específica da Too; não redefine automaticamente os contratos das outras seguradoras. Permanecem `periodoIndenitario = 30`, `indiceDeReajusteAluguel = IGP_M`, `percentualComissao = 15.0` e `tipoEntrada = 2` (Sem Entrada).

Selecionar a condição efetivamente retornada pelo passo 6, GET `/fianca/proposta/formaspagamento/{numeroCotacao}`, respeitando a origem e a quantidade efetiva: 29 parcelas sem entrada na base de 30 meses. Se a condição faltar, sinalizar indisponibilidade para comparação/download dessa condição, sem inventar valores, trocar silenciosamente a forma ou mudar aprovação para recusa.

#### Passo 6.1 — consultar PDF da cotação

- Método **POST**, apesar de ser uma consulta documental.
- Homologação: `https://openapi-uat.tooseguros.com.br/fianca/proposta/cotacao/{NumeroCotacao}/pdf`.
- Headers informados: `Authorization: Bearer <token>`, `ClientId` e `ClientSecret`; enviar o payload como JSON. Não expor credenciais no navegador.
- Path recebe o número da cotação. O body documentado usa `numeroFicha: {NumeroProposta}`; preservar essa distinção e os identificadores da mesma rodada. Não preencher numeroFicha com numeroCotacao por semelhança de nomes.

Estrutura documentada do body:

```text
numeroFicha: número da proposta/ficha
formaPagamento:
  tipoPagamento: 1 = Boleto; 2 = Fatura
  tipoEntrada: 1 = Com Entrada; 2 = Sem Entrada
  opcaoParcelamento: array de objetos
    tipoOpcaoParcelamento: 1 = À vista; 2 = Mensal; 3 = Até 4x; 4 = Até 36x
    quantidadeParcelas: quantidade de parcelas
```

A print traz dois itens ilustrativos em `opcaoParcelamento`; não estabelece que dois itens sejam obrigatórios. Para baixar a cotação exibida no card, preparar a opção correspondente à seleção real de 30 parcelas, sem entrada e ao meio definido pela origem. Aplicar a decisão posterior: Mensal (2) para todas as origens, Fatura (2) para imobiliária cadastrada e Boleto (1) para os demais, sempre quantidadeParcelas 30 no POST. Não escolher tipo 4 apenas porque 30 cabe em até 36x. Não reutilizar o shape plano do endpoint 7: aqui `tipoOpcaoParcelamento` e `quantidadeParcelas` ficam dentro de `formaPagamento.opcaoParcelamento[]`.

O usuário definiu download ao clicar em **Baixar cotação** no cartão Too, após visualizar a parcela. Fluxo pretendido: solicitar cotação → consultar formas de pagamento → selecionar/exibir condição de 30x → ao solicitar download, consultar PDF com os mesmos identificadores e condição. Card, comparação e PDF devem representar a mesma oferta da mesma rodada. Não criar nova cotação nem completar a proposta para obter esse documento.

#### Biometria, emissão e limites do escopo

O PDF contém link e QR Code de validação biométrica facial. Conforme a print, valem por **30 dias desde a pré-aprovação da ficha** e o link não pode ser reutilizado após a biometria. Isso descreve o link, não um prazo comprovado de validade da cotação ou do próprio arquivo PDF. Não reiniciar esse prazo ao baixar novamente.

O usuário esclareceu que os dados da imobiliária podem ser inseridos na atualização dos dados faltantes da proposta para emissão (passo 7). **Esse endpoint não será usado nesta versão**, que apenas analisa, compara e disponibiliza a cotação. Não tornar a atualização pré-requisito do PDF nem adicionar seus campos à ficha por suposição.

A print do passo 7 exige biometria aprovada para atualizar a proposta, nome do proprietário PF e razão social do proprietário PJ. Essas exigências pertencem à preparação de emissão e não foram documentadas como pré-condições da consulta do PDF. Não exigir biometria concluída para baixar um PDF que contém justamente o acesso à biometria.

PDF da cotação é distinto de parecer de crédito, PDF da proposta e apólice. O novo download não substitui o fluxo separado de parecer/cartas de recusa.

#### Impactos registrados para a implementação posterior

- `TooService::getQuotePdf()` ainda faz GET sem body. Adaptá-lo para POST com o payload específico acima quando a implementação for autorizada; o método atual não atende à print recebida.
- Reutilizar a seleção persistida do passo 6 e os identificadores da rodada, sem recalcular valores no download. Aplicar autorização de acesso ao lead e tratamento de falha documental sem desfazer o resultado da análise.
- Representação do PDF confirmada pela print posterior: JSON com Base64 em `result.pdfCotacao`. Conferir Content-Type e erros em resposta real; decodificar o campo para disponibilizar o arquivo.
- Conferir a associação real dos códigos de parcelamento e a condição selecionada no documento gerado. A regra de origem está definida; permanece a necessidade de conferir o retorno efetivo, sem inserir etapa de emissão.

Atualização apenas documental: nenhuma chamada remota, alteração de service/provider/builder, tela, configuração ou banco foi realizada.

### Correção Too — entrada, quantidade efetiva e melhor negócio

**Esclarecimento mais recente do usuário:** no prazo-base de 30 meses, a Too desconta uma parcela quando a condição é **Sem entrada**, retornando **29 parcelas**. Com entrada, a condição corresponde a 30 pagamentos, incluindo a entrada (1 + 29). Essa regra substitui a exigência anterior de exatamente 30 parcelas sem entrada para a Too. Não estender essa redução automaticamente à Pottencial ou Tokio.

As prints ilustram a mesma diferença em uma base de 24: `quantidadeParcelasPossiveis = 24`; grupo mensal `tipo = 2`, `comEntrada = false`, `numeroParcela = 23`, parcela 20,87; grupo mensal com o mesmo ID e tipo, `comEntrada = true`, `numeroParcela = 24`, parcela 20,00, descrição `1 + 23 x R$20.00`. Ambos informam total 480,00. Portanto, ID/tipo não identificam sozinhos a condição: preservar também entrada e alternativa. A diferença de arredondamento (23 × 20,87 = 480,01) não autoriza corrigir valores da API.

`quantidadeParcelasPossiveis` é a referência geral; `parcelas[].numeroParcela` informa a quantidade efetiva da alternativa. A regra de redução explica a seleção, mas não autoriza subtrair novamente da quantidade já retornada nem calcular o preço por divisão. A print comprova o exemplo 24/23; a extensão 30/29 é a confirmação explícita do usuário.

**Melhor negócio:** usar diretamente `opcoesParcelamento[].parcelas[].valor` como valor principal Too. A alternativa de 29 parcelas sem entrada no prazo-base de 30 meses participa da comparação; não excluí-la apenas por não ter 30 parcelas. Comparar o valor da parcela com as demais ofertas elegíveis, preservando moeda, vigência, cobertura, forma e composição dos valores. O destaque é de menor parcela, não garantia de menor custo total. Exibir a quantidade real (por exemplo, 29x sem entrada), sem rotular essa oferta como 30x. O padrão do sistema permanece Sem entrada; a explicação de Com entrada não autoriza trocá-lo silenciosamente para obter parcela menor.

**PDF — passo 6.1:** somente consultar por **POST** `/fianca/proposta/cotacao/{NumeroCotacao}/pdf`, enviando `numeroFicha` e `formaPagamento`. A seleção do pagamento é necessária para localizar/gerar a cotação correspondente. Enviar `tipoPagamento` (1 Boleto, 2 Fatura), `tipoEntrada` (1 Com Entrada, 2 Sem Entrada) e `opcaoParcelamento[]` com `tipoOpcaoParcelamento` definido pela origem e `quantidadeParcelas = 30`, conforme confirmação posterior. A opção mensal das prints tem tipo 2. Não mandar GET sem body nem confundir ficha com cotação. Confirmação posterior: enviar quantidadeParcelas = 30 ao PDF, inclusive quando o GET retornar 29 sem entrada; a API aplica a redução. Não recalcular a parcela. O endpoint 7 de atualização para emissão permanece excluído.

Continuam válidos: origem imobiliaria_cadastrada → Fatura; demais formulários → Boleto; período indenitário 30; IGP_M; comissão 15.0. Persistir prazo-base e quantidade efetiva separadamente, além de entrada, valor da parcela, total, códigos e identificadores da rodada. Card, comparação e PDF devem consumir a mesma seleção.

**Atualização de 29/10 — implementado, aguardando validação integrada:** o seletor Too, o resumo compartilhado e o comparador já admitem 30 meses/29 parcelas sem entrada. O POST do PDF continua pendente. Validar variantes com IDs repetidos, não descontar duas vezes, não fabricar preço e manter o POST do PDF coerente com a seleção. A solicitação anterior de alterar o comando foi retirada pelo usuário: nesta etapa, atualizar somente este estudo, sem mudar comandos ou fluxo de execução.

### Confirmação Too — payload e retorno do PDF, opções por origem

**Decisão vigente do usuário em 23/10/2026:** Mensal (2) para todas as origens; imobiliária cadastrada usa Fatura (2) e os demais formulários usam Boleto (1). A orientação anterior de À vista para os demais foi revogada. Aplicar a tabela abaixo ao POST `/fianca/proposta/cotacao/{NumeroCotacao}/pdf` (passo 6.1):

| Origem | `tipoPagamento` | `tipoEntrada` padrão | `tipoOpcaoParcelamento` | `quantidadeParcelas` enviada |
| --- | --- | --- | --- | --- |
| Imobiliária cadastrada | 2 — Fatura | 2 — Sem Entrada | **2 — Mensal** | **30** |
| Todos os demais formulários | 1 — Boleto | 2 — Sem Entrada | **2 — Mensal** | **30** |

Boleto é forma de pagamento, não sinônimo de pagamento à vista. Usar tipoOpcaoParcelamento 2 (Mensal) em ambos os cenários. Não selecionar À vista (1), Até 4x (3) ou Até 36x (4) como substitutos automáticos. Conferir no retorno real a condição e sua correspondência com o PDF.

**Quantidade enviada versus quantidade retornada:** enviar sempre **30** ao PDF, mesmo que o GET de pagamentos tenha retornado **29 parcelas sem entrada**. O desconto da parcela é responsabilidade da API; não enviar 29 por inferência nem descontar novamente. Esta confirmação substitui expressamente a orientação anterior de copiar a quantidade efetiva para o payload do PDF. Manter a quantidade efetiva e o valor retornados para exibição/comparação; a configuração de envio não autoriza rotular um retorno de 29 como 30 pagamentos.

**Fonte de entrada:** `C:\Users\Usuario\Downloads\payload-para-envio-opcao-pagamento-para-PDF-cotacao-TOO.txt`, lido nesta revisão. É um modelo documental com placeholders e comentários de domínio, não JSON diretamente executável (também há fechamentos incompletos). Preservar os nomes: `numeroFicha`, `formaPagamento.tipoPagamento`, `formaPagamento.tipoEntrada`, `formaPagamento.opcaoParcelamento[]`, com `tipoOpcaoParcelamento` e `quantidadeParcelas` em cada item. Os dois itens ilustrativos não comprovam obrigação de enviar duas opções; enviar a opção selecionada pela regra de origem.

Exemplo estrutural válido para imobiliária cadastrada, com número de ficha ilustrativo:

```json
{
  "numeroFicha": 123456,
  "formaPagamento": {
    "tipoPagamento": 2,
    "tipoEntrada": 2,
    "opcaoParcelamento": [
      {
        "tipoOpcaoParcelamento": 2,
        "quantidadeParcelas": 30
      }
    ]
  }
}
```

Para os demais formulários, alterar somente `tipoPagamento` para 1; manter `tipoOpcaoParcelamento = 2`, entrada 2 e quantidade 30. Usar o identificador real de ficha/proposta no body e o número real da cotação no path, sem confundi-los.

**Retorno confirmado pela documentação enviada:** `result.pdfCotacao` contém o PDF em **Base64**:

```json
{
  "result": {
    "pdfCotacao": "Base 64 do PDF da cotação"
  }
}
```

Na futura implementação, extrair esse campo, validar presença e decodificação Base64 estrita, verificar que o conteúdo decodificado é PDF e disponibilizar o download com autorização de acesso ao lead. Não tratar o texto Base64 como os bytes finais nem interpretar o campo como URL. Resposta ausente, Base64 inválida ou conteúdo que não seja PDF são falhas documentais, sem desfazer a aprovação da análise.

**Impacto na refatoração e melhor negócio:** preservar origem, códigos enviados, quantidade solicitada 30, quantidade efetivamente retornada e valor da alternativa em campos distintos. A opção Mensal (2) é comum a todas as origens; somente a forma Fatura/Boleto varia por origem. Não escolher arbitrariamente a menor parcela entre categorias. Comparação e card usam o valor retornado da alternativa correspondente; não dividir o total por 30, não substituir preço à vista total por mensalidade, nem inventar preço para uma opção ausente. Conferir com uma resposta real a correspondência desse valor com o PDF; a inclusão dos encargos no valor da parcela foi confirmada pelo usuário. Não executar o endpoint 7 de atualização para emissão.

Somente documentação foi atualizada. Comandos, services, providers e sistema de melhor negócio permanecem sem alterações nesta etapa.

#### Retorno de pagamentos recebido — confirmação do parcelamento para Boleto

Fonte: `C:\Users\Usuario\Downloads\retorno-endpoint-consultar-pagamentos-TOO.txt`. JSON validado: **7 grupos de parcelamento e 49 alternativas**, com catálogos separados de pagamento e entrada. O arquivo é corpo de resposta, não definição de uma nova rota; corresponde ao GET de formas de pagamento já estudado.

- `tipoPagamento`: 1 Boleto/Individual; 2 Fatura. `tipoEntrada`: 1 Com Entrada; 2 Sem Entrada. Não confundir esses códigos com os de `opcoesParcelamento[].tipo`.
- Opção 1 À Vista: uma parcela de 480,00. Ela não será usada no fluxo atual, inclusive para Boleto.
- Opção 2 Mensal: sem entrada, 23 parcelas de 20,87; com entrada, 24 pagamentos de 20,00, descritos como `1 + 23`. Ambos os grupos têm o mesmo ID e tipo, portanto preservar também `comEntrada` na seleção.
- Opções 3 Até 4x e 4 Até 36x também apresentam variantes com/sem entrada. Sua presença não altera a prioridade definida de Mensal.
- O exemplo informa `quantidadeParcelasPossiveis = 24` e vigência de 2023 a 2025; não comprova um teto atual de 24 nem substitui a regra de envio de 30. A observação do retorno explica a redução de 24 para 23 sem entrada; a regra 30/29 foi esclarecida pelo usuário.
- `valorTotal = 480.00` e `desconto = 0.0` são separados do preço em `parcelas[].valor`. Não recalcular esse preço nem corrigir diferenças de arredondamento. O arquivo não discrimina IOF/juros, mas o usuário confirmou posteriormente que o valor da parcela já inclui esses encargos; não somá-los novamente.

A dúvida anterior sobre usar preço integral à vista no ranking foi encerrada pela correção do usuário: selecionar Mensal em todos os formulários e usar a parcela dessa alternativa. Preservar a quantidade efetiva recebida para apresentação, enviar 30 no POST do PDF, e continuar sem chamar a atualização para emissão.

## Lacunas relevantes para a próxima etapa

1. **Contrato Too divergente:** obter a documentação correspondente às rotas de ficha, status v3 e cotação atualmente utilizadas, ou decidir explicitamente pelo fluxo do YAML. Pela decisão de 28/10, a reanálise futura usará o fluxo de nova solicitação, não a rota dedicada de reanálise. Preservar o código até a etapa de implementação autorizada.
2. **YAML não parseia como entregue:** Symfony Yaml rejeitou a linha 856, `nome: {{nomeProprietario}}`, por placeholder sem aspas. Para estudar estruturas, os placeholders desse formato foram colocados entre aspas somente em memória. O arquivo original continua intacto. Isso não equivale a validação completa OpenAPI.
3. **Autenticação Too:** confirmar headers versus Basic Auth/formulário antes de alterar o service.
4. **Schemas e catálogos incompletos:** confirmar status, tipos de documentos, planos, coberturas, pagamento, vigências, formatos de datas, escalas monetárias e comissões. Valores exemplificados não são enumerações exaustivas.
5. **PDF da decisão nos dois resultados:** confirmar parecer em aprovação e recusa, sobretudo a finalidade do `/draft` na Pottencial e a disponibilidade do `/parecer` na Too. Os services atuais tentam interpretar JSON e preservam `raw_body`; não implementam um contrato específico para download.
6. **Repetição e processamento assíncrono:** não foi encontrado contrato de idempotência, política de retries, limite de chamadas, webhook ou prazo de conclusão nas operações estudadas. Criação, solicitação de análise, atualização e eventual reanálise não devem ser consideradas seguras para repetição automática sem confirmação.
7. **Pottencial, atualização de 23/10:** um contrato para CPF/CNPJ e locação residencial/comercial. A API PJ separada foi descartada. Boleto sem imobiliária está definido; permanecem retornos reais em 30x e ajustes do contrato mantido. Em 26/10, o usuário confirmou funcionamento de ambas as rotas em testes anteriores: manter a atual e validar a alternativa durante o desenvolvimento, conforme revisão Pottencial.

Ambos os services dependem de `features.insurance_analysis.enabled` e da flag da companhia. Usam timeout de 30 segundos na autenticação e 60 nas operações; normalizam respostas em `success`, `http_status`, `endpoint`, `url`, `response`, `raw_body`, `headers` e, quando aplicável, `payload`. Sucesso HTTP não comprova aprovação de crédito ou emissão. Há logs de respostas integrais; a próxima implementação deve considerar os dados pessoais presentes nesses retornos.

## Verificação e estado da entrega

Inventário extraído dos arquivos locais: 11 operações Pottencial e 13 Too, mais uma autenticação por companhia. Coleção JSON analisada por parser; YAML analisado após ajuste de placeholders exclusivamente em memória. Os endpoints deste documento devem permanecer rastreáveis aos arquivos fonte; as rotas exclusivas do service foram identificadas separadamente.

Entrega documental apenas, com escopo atualizado conforme orientação do usuário para as cinco capacidades e PDF em aprovação e recusa. Nenhuma integração foi executada ou modificada. Próxima ação depende do comando do usuário.

<a id="regra-geral-de-pagamento-e-comparacao-em-30-parcelas"></a>

## Regra geral de reanálise como nova solicitação — 28/10/2026

**Complemento posterior de 28/10:** quando houver cônjuge, cada nova rodada também segue a regra geral de análises individuais e conjunta condicional descrita abaixo. A terceira solicitação conjunta é autorizada somente pelas duas recusas individuais confirmadas da mesma companhia, não por falhas técnicas ou recusas de outra seguradora.

**Decisão definitiva do usuário para todas as companhias:** a ação de reanálise do sistema iniciará uma **nova cotação ou nova solicitação de análise**, utilizando os endpoints de criação/solicitação de análise de cada seguradora. Não usar endpoints específicos de reanálise, motivos de reanálise ou recálculo da cotação anterior para executar essa ação. Esta regra substitui as propostas e pendências anteriores sobre escolher entre nova solicitação e reanálise dedicada.

| Companhia | Caminho da futura reanálise |
| --- | --- |
| Too | Reiniciar o fluxo de criação da ficha/proposta e solicitação de análise, seguindo as etapas obrigatórias de uma nova solicitação; não chamar `solicitar-reanalise` nem buscar motivos exclusivos desse endpoint |
| Pottencial | Criar novas cotações pelos endpoints de solicitar cotação, mantendo as três alternativas Basic/Complete/Traditional e suas regras de comparação; não chamar `/quotes/reanalysis` nem atualizar a cotação anterior para representar a nova rodada |
| Tokio | Criar uma nova cotação por POST `/cotar`, sem os headers `sqCdCotac` e `token` da cotação anterior; gerar/obter a autenticação OAuth conforme seu contrato. Não usar o modo de recálculo para a ação de reanálise |
| Demais companhias | Usar o respectivo fluxo de nova solicitação quando seu contrato estiver estudado e integrado, sem inventar endpoints |

Cada nova rodada usa os dados vigentes do lead e mantém os padrões já definidos de pagamento, comissão, elegibilidade e preenchimento. Preservar o histórico e os identificadores das rodadas anteriores, separando-os dos identificadores retornados na nova solicitação. Os IDs criados durante a nova rodada continuam sendo utilizados nas consultas e etapas subsequentes dessa mesma rodada; não eliminar dependências obrigatórias do fluxo de criação.

Respostas tardias de uma rodada anterior não podem sobrescrever a rodada atual. Reanálise de negócio não é retry de transporte: timeout, falha de conexão ou encaminhamento não devem iniciar novas cotações automaticamente. A única tentativa adicional automática de negócio aqui autorizada é a conjunta, após as duas recusas individuais do casal na mesma companhia, conforme regra específica abaixo; não repetir indefinidamente recusas. A decisão não autoriza emissão de apólice nem modifica o tratamento manual da Tokio para PJ.

Mensagens como `Cadastro Reprovado não passível de reanálise.` continuam preservadas como evidência da decisão anterior, mas a funcionalidade futura não tentará usar a operação dedicada de reanálise desse registro. A escolha do fluxo de nova solicitação está encerrada; não equivale a prometer que a seguradora aceitará ou aprovará uma nova submissão. Se a API impedir a nova solicitação, registrar o retorno efetivo sem contornar a restrição.

**Implementação adiada por determinação do usuário:** desenvolver somente após estudar todas as APIs e definir todas as regras. Nesta atualização, apenas documentação; não remover serviços, métodos, comandos ou testes existentes, nem alterar Jobs, providers ou chamadas atuais. Endpoints específicos antigos permanecem descritos como referência histórica do contrato, fora do caminho futuro de reanálise.

## Regra geral de cônjuges: individuais paralelas e conjunta condicional — 28/10/2026

**Decisão do usuário, aplicável a todas as seguradoras integradas:** quando a solicitação tiver cônjuge, realizar inicialmente duas análises separadas e simultâneas por companhia: uma para o solicitante principal e outra para o cônjuge. Cada análise individual considera somente a respectiva pessoa como pretendente da análise, com sua própria identidade. Se as duas forem recusadas, iniciar uma terceira solicitação com os dois juntos, como casados. Se pelo menos uma aprovar, não realizar a conjunta.

Esta regra substitui a ideia de sempre enviar o casal junto na primeira análise. Solicitações sem cônjuge mantêm o fluxo individual. Aplicar a regra por **seguradora e rodada**, respeitando elegibilidade (inclusive Tokio PJ manual), meios de pagamento, comissão, planos e demais padrões já definidos. Não criar mais seguradoras no lote por haver mais pessoas, planos ou solicitações remotas.

### Fluxo de decisão por companhia

| Resultado individual do solicitante | Resultado individual do cônjuge | Ação |
| --- | --- | --- |
| Aprovado | Aprovado | Não enviar conjunta; comparar as ofertas elegíveis e destacar a menor parcela |
| Aprovado | Recusado | Não enviar conjunta; apresentar a oferta aprovada e o registro de recusa do outro |
| Recusado | Aprovado | Não enviar conjunta; apresentar a oferta aprovada e o registro de recusa do outro |
| Recusado | Recusado | Enviar uma única terceira solicitação com os dois como casados |
| Pendente/processando ou falha técnica em qualquer uma, sem duas recusas confirmadas | Qualquer resultado | Não considerar satisfeita a condição da conjunta; aguardar conclusão ou tratar a falha |
| Aprovado, mas preço ausente/indisponível | Qualquer resultado | A aprovação impede a conjunta; ausência de preço não é recusa |

A condição é local à companhia: aprovação em outra seguradora não elimina a conjunta de uma companhia cujas duas individuais foram recusadas. Uma recusa individual não gera a conjunta antes de conhecer a decisão da outra. Se uma aprovar enquanto a outra ainda estiver em andamento, a conjunta já está dispensada, mas a outra individual continua para permitir comparar as duas se também aprovar. O destaque definitivo deve respeitar a conclusão das alternativas daquela rodada; não anunciar vencedor final enquanto uma individual ainda puder produzir oferta concorrente.

A terceira usa os dois pretendentes juntos, mantendo suas identidades e os domínios de casado exigidos por cada API. É uma nova cotação/solicitação pelos endpoints de criação, não uma chamada a endpoint específico de reanálise. Se for recusada ou falhar, não criar quarta tentativa de negócio. Recuperações técnicas de consultas não são novas tentativas de crédito, e timeout de criação não autoriza duplicar a solicitação.

### Identidade, estado civil e dados compartilhados

- Congelar os dados de cada pessoa e do imóvel na rodada. O cônjuge não pode herdar nome/CPF do solicitante nem dados pessoais que não lhe pertencem. A separação das solicitações não autoriza alterar silenciosamente o estado civil cadastrado para solteiro.
- O fato de uma pessoa ser enviada sozinha torna-a a principal daquela solicitação, conforme o contrato da companhia. Não confundir papel principal na requisição com vínculo empresarial, responsabilidade financeira, comissão do corretor ou participação societária.
- O imóvel, aluguel, condições, origem do formulário e vínculo com imobiliária continuam os mesmos nas duas individuais e na eventual conjunta. Não dividir o aluguel por dois nem somar preços de individuais para fabricar o preço da conjunta.
- **Responsabilidade financeira confirmada em 28/10:** na Tokio, ambos os pretendentes recebem `respondeFinanceiramente = S`. Nas análises individuais, enviar S para a pessoa analisada; na conjunta condicional, enviar S nos dois objetos pessoais. O cônjuge continua responsável financeiro mesmo com `pretendentePrincipal = N`. Essa decisão encerra a dúvida sobre quem recebe o indicador. Em confirmação posterior, o usuário definiu renda de 4 × aluguel para cada um, inclusive na conjunta, sem divisão e sem proporcionalidade aos 50% de participação. Nas demais APIs, respeitar os respectivos contratos, sem copiar o nome/código do campo Tokio por inferência.
- A representação de múltiplas pessoas em cada API será aplicada pelo respectivo adapter. Os códigos Tokio não devem ser enviados para campos descritivos das demais companhias. A regra de `commissionedAgents` e a comissão do Broker não mudam por haver casal.

### Tokio comercial PF: dois Titulares, 50% cada

**Definição expressa do usuário:** os dois pretendentes serão **Titulares**, código Tokio `21147`, com **`percentualParticipacao = 50` para cada um** e `vinculoEmpresa = S`. Isso substitui a hipótese de principal Titular/cônjuge Sócio e o exemplo de Sócio `21149` com participação 100 como padrão desse cenário.

Preservar o vínculo e a participação definidos para cada pessoa ao montar as solicitações; separá-las para análise não autoriza redistribuir automaticamente sua participação para 100%. Na conjunta, os dois percentuais somam 100%. Essa definição vale para o casal comercial PF. Para comercial PF sem cônjuge, o usuário confirmou posteriormente Titular com `percentualParticipacao = 100`. Não confundir a pessoa sem cônjuge com uma das análises individuais do casal, cujas participações permanecem 50 cada. Esses campos não são impostos às outras APIs.

Na Tokio, a individual de cada pessoa contém somente ela como pretendente pessoal principal (`pretendentePrincipal = S`), além do objeto empresarial exigido no comercial. Na conjunta, preservar a estrutura observada em T17: um objeto de dados empresariais e dois objetos pessoais; principal original com S e cônjuge com N. Ambos continuam Titulares com 50%, mesmo havendo apenas um indicador principal S. Para a conjunta como casados, `estadoCivil = 18490` nos dois, conforme o domínio Tokio já recebido. Não há CPF cruzado nem necessidade de transformar o cônjuge em Sócio.

Esses preenchimentos descrevem a montagem pretendida com base nas decisões e fragmentos recebidos; a aceitação do payload completo individual/conjunto será validada na integração. A ausência de suporte PJ na API Tokio permanece: não converter lead CNPJ em PF para executar essas tentativas.

### Composição com os três planos da Pottencial

A regra de Basic, Complete e Traditional permanece. **Cada cenário de pessoas** (solicitante sozinho, cônjuge sozinho e eventual casal) tem as suas cotações por plano. Assim, haverá seis cotações iniciais por rodada Pottencial com cônjuge (dois cenários × três planos) e até nove se a conjunta for necessária. São até três cenários de análise, não nove companhias ou nove rodadas.

Dentro de cada individual, qualquer plano aprovado torna aquela individual aprovada; recusa de todos os três planos confirma sua recusa. Falha, processamento ou ausência de preço não equivalem a recusa de plano. A conjunta só começa quando ambas as individuais forem recusadas, isto é, os três planos de cada individual tiverem sido recusados, preservando a regra anterior de recusa total Pottencial. Recusas de dois planos isolados não bastam.

Selecionar primeiro a melhor oferta elegível entre os planos de cada cenário, depois comparar as individuais aprovadas da companhia. Caso ambas sejam recusadas, comparar os planos aprovados da conjunta. Preservar o plano, participantes, coberturas, quoteId e documento correspondentes ao preço escolhido. A proposta de menor parcela pode ter coberturas distintas; não afirmar equivalência de proteção por ser mais barata.

### Painel, e-mail e seleção do melhor negócio

No painel de corretores **e** imobiliárias, a seção da companhia deve apresentar todas as análises de pessoas efetivamente realizadas naquela rodada, identificando individual do solicitante, individual do cônjuge e conjunta quando existir. Para recusas, apresentar **nome do pretendente e mensagem de recusa**; na conjunta recusada, identificar os dois nomes. Não exibir preço zero, total, parcela ou erro técnico como se fossem uma oferta recusada. Mensagens internas de transporte/credenciais não são mensagens de recusa para o cliente.

Havendo aprovação, destacar o **menor valor final de parcela** entre as ofertas elegíveis daquela companhia, indicando de quem é a análise (e o plano, quando aplicável). Se somente uma individual aprovar, usar sua oferta; se ambas aprovarem, calcular a diferença entre seus valores e escolher a menor; se a conjunta aprovar, usar a oferta conjunta. As outras análises permanecem identificadas no histórico do painel, sem esconder recusas nem apresentar todas como a vencedora.

Página e e-mail devem consumir a mesma seleção persistida da rodada, incluindo a identidade do pretendente/casal e a referência da cotação. Não combinar preço de uma pessoa com documento ou coberturas de outra. A comparação geral entre seguradoras usa a melhor oferta elegível de cada uma, mantendo 30 meses/parcelas e a exceção Too Sem entrada já confirmada, sem recalcular o valor unitário retornado. Em empate, informar igualdade de preço; não inventar preferência por solicitante/cônjuge.

A recusa de uma individual não deve concluir a companhia como recusada nem disparar e-mail global de recusa enquanto a outra individual ou a conjunta necessária ainda estiver em andamento. A companhia só conclui recusa do casal quando as duas individuais e a conjunta tiverem sido recusadas. Falha da conjunta após duas recusas individuais não é terceira recusa: preservar os resultados e indicar a falha. Aprovação da companhia depende de ao menos uma aprovação elegível de análise; o preço pode estar indisponível sem alterar essa decisão cadastral.

O download de documentos já previsto permanece vinculado às cotações/planos correspondentes. Falha documental não dispara conjunta nem modifica aprovação/recusa. A regra de menor preço no e-mail não altera os destinatários já definidos por origem do formulário.

### Persistência, concorrência e implementação futura

Registrar sob a mesma rodada e seguradora cada cenário de pessoas, os participantes próprios, resultado, mensagens, identificadores remotos, payload/condições congelados e alternativas de preço/documento. Para Pottencial, acrescentar o plano dentro do cenário. **Implementado em 29/10:** models, migration e jobs representam rodada, execução da companhia, cenário e cotação; cada cotação possui identificação própria. Falta validação integrada com as seguradoras.

Disparar as duas individuais em paralelo. A avaliação de suas conclusões deve autorizar a conjunta **uma única vez**, mesmo que os dois Jobs terminem simultaneamente ou uma notificação seja repetida. Conferir os resultados da mesma rodada e companhia, sem misturar respostas antigas. Concluir o progresso da companhia somente após resolver os cenários necessários, mantendo os contadores de seguradoras separados dos de cenários/planos.

Esta é uma exceção de negócio explícita à orientação anterior de não criar nova cotação automaticamente após recusa: somente duas recusas individuais do casal autorizam a conjunta. Não há loop de CPFs, novas combinações ou tentativas até obter aprovação. A regra geral de reanálise manual inicia outra rodada pelo fluxo de criação e reaplica esta mesma sequência quando houver cônjuge.

**Somente estudo nesta etapa:** não alterar comandos, providers, Jobs, banco ou formulários agora. Implementação após concluir o estudo de todas as APIs e as definições de regras. Validações futuras relevantes: duas aprovações; uma aprovação; duas recusas seguidas de conjunta aprovada/recusada; falha técnica; aprovação sem preço; simultaneidade da segunda recusa; resposta de rodada antiga; isolamento entre seguradoras; planos Pottencial; e coerência do vencedor entre painel, e-mail e documentos.

## Regra geral de coberturas por origem do formulário — 28/10/2026

**Decisão do usuário para todas as seguradoras:** solicitar as quatro coberturas adicionais de danos ao imóvel, pintura interna, pintura externa e multa por rescisão, com exceção dos leads originados do formulário de locatário. Na Tokio, enviar os indicadores abaixo como strings S; nas demais companhias, solicitar as coberturas equivalentes usando os nomes, códigos e estruturas dos respectivos contratos, sem copiar literalmente os atributos Tokio.

| Cobertura | Indicador Tokio | Formulários de imobiliária cadastrada e imobiliária não cadastrada/proprietário |
| --- | --- | --- |
| Danos ao imóvel | `icDanosImovel` | `S` |
| Pintura interna | `icPinturaInterna` | `S` |
| Pintura externa | `icPinturaExterna` | `S` |
| Multa por rescisão | `icMultaRescisao` | `S` |

**Exceção prioritária — `resources/views/simulation/forms/tenant.blade.php` (locatário):** enviar somente o valor do aluguel como cobertura para qualquer seguradora. Não enviar as quatro coberturas adicionais, água ou luz. A expressão “apenas o valor do aluguel” também exclui os demais encargos/coberturas opcionais, como condomínio, IPTU e gás, do conjunto de coberturas desse fluxo. Os dados obrigatórios de identificação, imóvel, análise e pagamento continuam presentes conforme o contrato; a exceção não transforma o payload inteiro em um único campo de aluguel.

Na Tokio, omitir os quatro indicadores e os valores de encargos no fluxo de locatário, enviando `vlAluguel` no objeto de coberturas. Não substituir a omissão por S, zero ou coberturas extras herdadas de padrões. Nas demais APIs, aplicar a mesma seleção de somente aluguel pela estrutura documentada. Validar na integração eventuais coberturas incluídas automaticamente pela seguradora ou pelo plano; não afirmar que foram excluídas apenas porque não foram solicitadas.

A seleção depende da **origem real do formulário**, e não apenas de haver ou não imobiliária vinculada. O formulário de imobiliária não cadastrada/proprietário mantém as quatro coberturas adicionais, apesar de compartilhar regras de pagamento com locatário em algumas companhias. Fora do fluxo tenant, esta decisão não determina valores fictícios de água, luz ou outros encargos: preservar as regras e os dados próprios desses campos.

Aplicar a mesma seleção de coberturas às duas análises individuais e à eventual conjunta do casal, às novas rodadas e aos planos de cada companhia. Na Pottencial, preservar os três planos e suas diferenças de cobertura retornadas; verificar a compatibilidade da seleção solicitada por origem sem inventar parâmetros ou alterar silenciosamente a regra. Documentos, painel e e-mail devem refletir as coberturas efetivamente retornadas da cotação selecionada.

**Pendência de escolha das quatro coberturas encerrada:** a regra é habilitá-las fora de tenant e enviar somente aluguel em tenant. Permanecem para a implementação o mapeamento por seguradora/plano e a conferência do retorno. Esta atualização é exclusivamente documental; não altera builders, formulários, comandos ou banco.
## Regra geral de vigência do contrato e do seguro — 28/10/2026

**Definição expressa do usuário para TODAS as seguradoras:** tanto o contrato de locação quanto o seguro terão início na data em que a análise for realizada e término 30 meses após essa data. A duração de ambos será de 30 meses; a origem dessas datas não é mais uma pendência.

| Data | Regra |
| --- | --- |
| Início do contrato | Data da análise |
| Início do seguro | Mesma data de início do contrato |
| Fim do contrato | Data inicial acrescida de 30 meses de calendário |
| Fim do seguro | Mesma data de término do contrato |

Exemplo: uma análise realizada em 28/10/2026 terá início de contrato e seguro em 28/10/2026 e término em 28/04/2029. Calcular meses de calendário, não 900 dias. Como critério técnico para fins de mês, preservar o dia quando existir no mês de destino; caso contrário, usar o último dia válido desse mês, sem transbordar para o mês seguinte.

Na implementação, fixar a data-base da rodada no fuso horário do negócio (America/Sao_Paulo) e reutilizá-la nas seguradoras, planos, análises individuais e eventual conjunta daquela rodada, evitando divergências por execução assíncrona ou virada de dia. Uma nova rodada de análise terá nova data-base correspondente à sua realização; consultas, PDFs e recuperação técnica da mesma cotação não devem recalcular sua vigência. Preservar as datas utilizadas no histórico.

Cada integração deverá formatar essas mesmas datas conforme seu contrato. Na Tokio, usar DD/MM/AAAA nos campos de vigência do contrato e do seguro. Não confundir término da vigência com validade da cotação, prazo de transmissão ou vencimento das parcelas. A regra de vigência de 30 meses mantém a exceção de pagamento Too Sem entrada: prazo-base de 30 meses com 29 parcelas efetivas, sem reduzir a duração do contrato ou seguro.

Esta decisão substitui vigências anuais dos exemplos e comandos diagnósticos como referência para produção. Permanecem os limites e validações de cada API; uma divergência no retorno deverá ser identificada, sem alterar silenciosamente as datas solicitadas. Nesta etapa, apenas documentação: não modificar comandos, builders ou execução das análises.
## Regra geral de pagamento e comparação em 30 parcelas

Definida em **13/10/2026**, a pedido do usuário. Aplica-se a Too, Pottencial, Tokio e Porto, quando integradas, na página de análise, nos resumos e nos documentos. Esta seção prevalece sobre referências históricas a comparação por total ou a um teto de até 30 parcelas.

### Base de pagamento

**Parcelas iguais — confirmação geral do usuário em 26/10/2026:** em todas as companhias, os valores das parcelas são iguais dentro de cada alternativa de pagamento, independentemente da quantidade contratada. Essa confirmação encerra a dúvida sobre primeira, intermediárias ou última parcela com preços diferentes. Alternativas distintas (por exemplo, quantidades ou entrada diferentes) podem ter valores de parcela distintos entre si; a igualdade se aplica às parcelas da mesma alternativa. Usar diretamente o valor final informado pela API, já com IOF e encargos. Na Pottencial, usar o valor comum do array interno de parcelas da alternativa selecionada; não calcular média, escolher o menor elemento nem dividir o total. A conferência futura da igualdade no retorno é validação técnica dessa regra, não uma nova pendência de negócio. Uma resposta divergente deve ser tratada como inconsistência, sem corrigir valores silenciosamente. Preservar o total reportado mesmo quando o produto quantidade × parcela diferir por arredondamento.

**Valor unitário retornado — complemento confirmado pelo usuário em 26/10/2026:** todas as APIs fornecem o valor de uma parcela, que se repete para a quantidade de parcelas da condição selecionada. Esse valor unitário é a fonte direta para exibição e comparação do melhor negócio, independentemente da quantidade de parcelas. Não dividir esse valor pela quantidade nem interpretar o campo de parcela como o total do contrato. A estrutura da resposta continua específica de cada API (campo ou array); extrair o valor de parcela correspondente à opção selecionada, preservando sua quantidade e as demais regras de pagamento já definidas.

**Composição da parcela — pendência encerrada em 23/10/2026:** o usuário confirmou, como regra de negócio do seguro fiança adotada neste projeto para todas as companhias, que o valor da parcela retornado já inclui IOF, juros e demais encargos aplicáveis, representando o valor final cobrado. Usar diretamente o campo de parcela da alternativa selecionada: `opcoesParcelamento[].parcelas[].valor` na Too, o valor de parcela da simulação na Pottencial e `vlPremPar` na Tokio. Não somar encargos novamente nem recalcular a parcela a partir do total. Campos discriminados de juros/IOF servem para detalhamento, não acréscimo ao preço. A ausência dessa discriminação não impede o ranking por parcela. Esta confirmação fornecida pelo usuário substitui as pendências anteriores de composição; não transforma campos de prêmio total ou líquido em parcelas. Nenhuma chamada remota ou alteração de código decorre deste registro.

**Exceção vigente da Too:** a regra histórica de igualdade entre 30 meses e 30 parcelas abaixo foi refinada: Sem entrada resulta em 29 parcelas na base de 30 meses. Essa oferta é elegível pelo valor da parcela retornada. Para as demais companhias, preservar as regras próprias. Esta exceção prevalece sobre menções anteriores a exatamente 30x.

**Confirmação posterior do usuário em 23/10/2026: 30 meses = 30 parcelas mensais, uma parcela por mês.** Para Too e Pottencial, essa é uma única condição de pagamento do sistema, não dois cenários distintos. A regra orienta a comparação compartilhada e a futura integração Tokio: comparar o valor de uma parcela mensal durante 30 meses. Esta confirmação substitui as ressalvas anteriores de periodicidade mensal ainda não definida. Os valores serão extraídos das condições retornadas pelas seguradoras, preservando a forma de pagamento e os encargos de cada oferta.

- A condição padrão é **exatamente 30 parcelas mensais ao longo de 30 meses, uma por mês**. O preço principal é **o valor de uma parcela dessa condição de 30x**, não o prêmio total nem o valor de uma condição com outra quantidade de parcelas.
- “Fatura de 30 vezes” define aqui a base de parcelamento. Não muda automaticamente a forma de pagamento de todos os solicitantes para `faturado`; aplica-se a regra de presença de imobiliária definida em 23/10 e os meios de pagamento próprios de cada companhia.
- Solicitar 30 parcelas quando o contrato da companhia permitir configurar a quantidade; quando a API retornar opções, selecionar somente a condição cuja quantidade confirmada seja 30. Não inventar um campo de requisição nem assumir que LMI define o parcelamento.
- Não dividir prêmio total por 30 para fabricar uma opção que a seguradora não ofereceu. Não converter 12x, 24x ou outra quantidade em 30x. Não multiplicar a parcela de outro plano para simular essa condição.
- **30 meses de pagamento e 30 parcelas são a mesma condição operacional**, com uma cobrança mensal. `multiploLMI` continua sendo o campo de limite de indenização no contrato Tokio; preencher esse campo e as datas de contrato/seguro conforme seus contratos, sem trocar seus significados pelo de um campo de pagamento. A regra mensal aprovada não autoriza omitir datas ou alterar a semântica dos atributos da API.
- O preço deve ter moeda identificada e periodicidade mensal, já confirmada pelo usuário. Para BRL confirmado/configurado, apresentar “30 parcelas mensais de R$ ...” ou “R$ ... por mês, durante 30 meses”, respeitando os valores retornados.

### Configuração futura de seguradoras e padrões fixos atuais

**Esclarecimento do usuário em 23/10/2026:** a futura página no dashboard dos corretores permitirá definir, por seguradora, as formas e condições de pagamento desejadas nas análises e outros parâmetros que possam ser alterados **sem interferir no resultado final da proposta**. A página será desenvolvida após concluir e validar o sistema de análise. Até lá, usar os padrões fixos abaixo, sem criar configuração editável nesta etapa.

| Parâmetro | Padrão atual aprovado |
| --- | --- |
| Prazo e frequência | **30 meses = 30 parcelas mensais**, uma por mês |
| Entrada | **Sem entrada**; não selecionar condições com entrada para o ranking padrão |
| Com imobiliária vinculada à cotação | Fatura, conforme elegibilidade e representação exigida pela companhia |
| Origem imobiliária cadastrada — Too | Fatura, tipo 2, no prazo-base de 30 meses, com 29 parcelas sem entrada |
| Demais origens de formulário — Too | Boleto, tipo 1, no prazo-base de 30 meses, com 29 parcelas sem entrada |
| Sem imobiliária — Tokio | Crédito, respeitando o contrato da API |
| Sem imobiliária — Pottencial | `Boleto` para PF e PJ |
| Comissão por seguradora | **15%**, fixos enquanto não existir a página de configuração; futuramente editáveis pelo corretor para cada companhia |
| Índice de reajuste Too | `IGP_M` |
| Família Pottencial | Contrato compartilhado CPF/CNPJ; manter rota mensalizada atual e preservar rota geral como alternativa configurável e testável |

**Comissão — decisão posterior do usuário:** enquanto a página não existir, utilizar **15% para todas as seguradoras**. Na futura página, os corretores poderão alterar o percentual individualmente por companhia, respeitando os limites contratuais. Essa autorização inclui comissão no escopo mesmo que sua alteração afete o prêmio; não significa autorização para modificar a decisão de crédito retornada pela seguradora. Nenhum outro padrão fixo foi alterado.

15% é o valor de negócio; a representação numérica deve seguir cada contrato. Onde a API utiliza fração, 15% corresponde a `0.15`; onde utiliza pontos percentuais, corresponde a `15`. Não enviar o mesmo número indiscriminadamente: o contrato Pottencial mantido exemplifica escala fracionária (`0.15`); na Too a escala foi confirmada pelo usuário: `percentualComissao = 15.0` para 15%; na Tokio, enviar `pcComissao = 15`, conforme padrão confirmado e retorno real T19. A faixa documentada Too de 10% a 40% comporta 15%; respeitar também regras de soma de comissões/co-corretagem. Na Pottencial, o usuário definiu posteriormente enviar os 15% somente no Broker e omitir comissão no PolicyOwner; não atribuir 15% a cada agente. Não presumir distribuição adicional nas outras companhias.

**Percentuais adicionais — decisão de 28/10/2026:** a futura página se chamará **Gerenciar seguradoras**, no dashboard dos corretores. Além da comissão por companhia, permitirá ajustar os percentuais adicionais aceitos por cada contrato para influenciar os valores cotados pela API. Enquanto a página não existir, manter os seguintes padrões de entrada Tokio:

| Campo | Padrão numérico enviado |
| --- | --- |
| `pcComissao` | `15` (15%) |
| `pcRemuneracao` | `0` |
| `pcDesconto` | `0` |
| `pcAgravo` | `0` |

A escolha desses percentuais está encerrada; zero é regra de negócio atual, não apenas um valor de exemplo. Isso não altera os 50% de participação de cada pretendente nem a participação do Broker: são campos de outra finalidade. Não zerar percentuais calculados e retornados pela seguradora, como juros, nem presumir que o campo de saída `pcProLabore` seja um parâmetro de entrada.

**Preparação para o desenvolvimento:** centralizar os padrões por seguradora em configuração consumida pelos builders, evitando literais repetidos em controllers, Jobs e providers. Prever que essa fonte de parâmetros possa ser substituída pelas configurações persistidas da futura página, mantendo os padrões acima quando não houver configuração. Validar campos permitidos, tipos, escala, limites e permissão de edição por companhia; não criar parâmetros equivalentes inexistentes nas outras APIs. Preservar os valores efetivamente utilizados em cada rodada para histórico e comparação; alterações futuras não devem modificar retroativamente cotações já realizadas. A aplicação dos novos percentuais ocorrerá em novas solicitações, usando os preços efetivamente retornados, sem manipular localmente os resultados da seguradora.

Nesta etapa, registrar o desenho e os padrões; não implementar página, migrations ou refatoração de configuração. A preparação concreta dos builders será feita na etapa de desenvolvimento da integração.
**Estado da implementação:** os valores de configuração e os fallbacks existentes não foram alterados nesta entrega documental. Alinhar a montagem dos payloads com o padrão de 15% na etapa de implementação; até lá, este registro não comprova que o código já envie 15% em todas as companhias.

Na Too, o exemplo documenta `tipoEntrada` tipo **2 = Sem Entrada** e grupos com **`comEntrada = false`**. Selecionar a alternativa retornada de **29 parcelas sem entrada para a base de 30 meses**, conforme esclarecimento posterior. A decisão de entrada está encerrada: não escolher a variante com entrada para obter 30x quando a condição sem entrada não estiver disponível. Os catálogos de forma, entrada e parcelamento continuam exigindo associação válida.

Na Pottencial, Sem entrada é regra fixa confirmada pelo usuário; não exigir indicador adicional nem inventar o campo `tipoEntrada` da Too. Na Tokio, usar os atributos documentados para a condição funcional definida. Não transportar campos entre contratos. Primeiro vencimento e entrada não são automaticamente sinônimos: um pagamento mensal inicial não deve ser classificado como entrada apenas por ser o primeiro.

A configuração futura selecionará condições suportadas pela seguradora: enviar parâmetros quando houver campo de requisição documentado; quando o endpoint retornar alternativas, filtrar e selecionar no sistema. Ela não obriga a API a devolver uma opção inexistente, não fabrica valores e não sobrescreve aprovação, recusa ou análise pendente. Se faltar a condição exigida, mostrar indisponibilidade para comparação, preservando a decisão da companhia.

A expressão “sem interferir no resultado final” é um critério de elegibilidade dos parâmetros da futura tela, não uma garantia de que qualquer campo seja neutro. Confirmar por companhia quais alterações não mudam a decisão de aceitação; forma de pagamento, encargos ou desconto podem mudar valores financeiros sem representar nova decisão de crédito. A comissão e, posteriormente, os percentuais adicionais foram explicitamente autorizados pelo usuário como parâmetros editáveis por seguradora na futura página Gerenciar seguradoras, conforme os campos suportados por cada contrato. Dados de risco, coberturas, índice e demais parâmetros não ficam automaticamente liberados para edição por essa orientação. Definir a lista permitida com base no contrato e nas regras aprovadas, preservando os valores usados em cada rodada para histórico e comparação.

### Seleção da condição e do preço

| Informação | Regra comum |
| --- | --- |
| Quantidade e periodicidade | Prazo-base de 30 meses; Too sem entrada retorna 29 parcelas elegíveis; preservar as regras das demais companhias |
| Situação | Proposta aprovada com decisão confirmada; HTTP 2xx sozinho não basta |
| Valor principal | Valor positivo e confirmado de uma parcela da condição de 30x |
| Total | Conservar o total informado pela companhia em campo separado; não substituir pelo valor da parcela |
| Juros e IOF | Inclusão no valor final da parcela confirmada pelo usuário para todas as companhias; conservar discriminação quando retornada e nunca somar novamente |
| Assistência, produto e coberturas | Manter associados à mesma condição escolhida |
| Entrada | Sem entrada como padrão fixo; excluir condições com entrada, sem fallback silencioso |
| Igualdade das parcelas | Confirmada pelo usuário para todas as companhias, dentro de cada alternativa; usar o valor comum retornado e tratar eventual divergência como inconsistência técnica |
| Validade e rodada | Utilizar oferta vigente da rodada atual; respostas antigas não substituem a atual |

Na **Tokio**, selecionar `calculos.{assistencia}.opcoesPagamento.{forma}.prazo.{opcao}` com **`qtd = 30`** e destacar **`vlPremPar`**. A chave da opção pode ser `"1"` e não deve ser confundida com a quantidade. Não substituir por `sugestaoValorParc` de outra condição.

Para Too, Pottencial e Porto, mapear os campos equivalentes conforme os respectivos contratos e respostas reais. Não atribuir a essas APIs os nomes de campos da Tokio nem usar `grossPremium`/prêmio total como se fosse parcela. A falta desse mapeamento é uma pendência técnica, não autorização para estimar preços.

### Formas de pagamento

- **Regra geral de 23/10/2026:** fatura exige imobiliária presente na cotação; sem imobiliária preenchida, usar crédito, **exceto Pottencial e Too, que usarão boleto**, conforme esclarecimento posterior do usuário. Substitui a alternativa anterior débito/crédito para locatários sem imobiliária e resolve também o proprietário sem imobiliária.
- **Com imobiliária presente:** fatura é elegível; manter faturado como padrão das cotações de imobiliárias, conforme contrato e disponibilidade. Tipo de solicitante e `company_id` isolados não comprovam os dados enviados à seguradora.
- **Tokio sem imobiliária:** `credito`, modalidade `prazo`, quantidade confirmada 30. Não selecionar débito como fallback automático.
- **Pottencial sem imobiliária:** boleto (`Boleto`) no contrato compartilhado por PF e PJ. A opção foi autorizada pelo usuário e substitui a exigência anterior de crédito para esta companhia.
- **Too — regra por origem:** imobiliaria_cadastrada usa Fatura (tipo 2); todos os demais formulários usam Boleto (tipo 1), no prazo-base de 30 meses, com 29 parcelas sem entrada. Essa regra específica prevalece sobre o critério genérico de presença de imobiliária.
- **Tokio:** manter crédito sem imobiliária e fatura conforme elegibilidade, usando os campos/códigos de seu contrato.
- Se faltar a forma exigida ou a condição de 30x, registrar a indisponibilidade; não mudar para outro meio ou quantidade sem decisão explícita.

### Comparação e destaque

1. A unidade de comparação é a **parcela confirmada de um plano de 30x**. Na Too, admitir 29 parcelas sem entrada na base de 30 meses; as ofertas devem ter uma base monetária equivalente; não comparar prêmio líquido de uma com parcela bruta de outra.
2. Comparar propostas da mesma finalidade de locação, com moeda, vigência, periodicidade e escopo de cobertura compatíveis. Mesmo número de parcelas não torna produtos diferentes automaticamente equivalentes.
3. A proposta vencedora é a de **menor valor por parcela entre as ofertas elegíveis em 30x**. A seleção deve ser única e compartilhada entre página e preparação do PDF, sem repetir a regra em controllers ou Blade.
4. O destaque definitivo continua condicionado à conclusão do pacote. Análises pendentes não são ofertas vencedoras; falta de preço ou valor zero/null não representa gratuidade.
5. Excetuada a condição Too de 29 parcelas sem entrada na base de 30 meses, uma oferta sem 30x pode continuar visível como resultado da companhia, com indicação “Sem condição de pagamento em 30x”, mas fica fora desse ranking. A ausência de parcelamento não altera uma aprovação para recusa ou falha técnica.
6. Se houver companhias fora da comparação, apresentar o destaque como **melhor parcela entre as ofertas comparáveis em 30x**, indicando as exclusões. Não afirmar que é a mais barata entre todas as companhias. Se nenhuma oferta for elegível, não há destaque.
7. Quando a exclusão decorrer de informação desconhecida, como moeda ou valor da parcela ausentes, identificar “Comparação indisponível para esta oferta” e o motivo. Não usar zero como fallback nem tratar desconhecido como condição de 30x inexistente.
8. Empate em centavos representa empate de preço; não afirmar vantagem exclusiva. A ordenação visual pode ser estável, mas não deve inventar preferência comercial. Para um PDF que exige uma única oferta detalhada, o critério de desempate ainda precisa ser definido.
9. O ranking é de parcela, não uma afirmação genérica de menor custo total. O usuário confirmou que as parcelas incluem IOF/juros e demais encargos em todas as companhias. Para Tokio, usar `vlPremPar` diretamente, sem adicionar `vlJurPar` ou `vlIofParc`.

### Página, documentos, persistência e efeitos no código

O card deve informar produto/assistência, situação, quantidade efetivamente retornada (**29x sem entrada na Too para base de 30 meses**), valor da parcela e forma de pagamento. Total, encargos, coberturas e vigência ficam disponíveis para detalhamento. Na falta de dados válidos, usar mensagem de indisponibilidade, nunca um preço simulado.

Página, PDF próprio e resumo de envio devem usar a mesma condição de pagamento e a mesma seleção de melhor oferta. Preservar o detalhamento completo da oferta escolhida e os valores de parcela/quantidade das demais comparáveis. A entrega global de cartas de recusa continua condicionada à recusa unânime; a revisão Pottencial de 26/10 determina consultar a carta de cada plano recusado; falha de comparação ou ausência de 30x não dispara cartas de recusa.

Persistir junto à rodada: quantidade, valor da parcela, total reportado, moeda, forma/modalidade, periodicidade, juros/IOF e sua composição, produto/assistência, coberturas, vigência e referência da opção remota. Não sobrescrever o histórico nem reinterpretar um snapshot antigo por total como se já fosse uma oferta em 30x.

**Implementação pendente:** `AnalysisQuoteSummary` precisa representar parcelas sem alterar o significado dos campos de total; `AnalysisQuoteComparison` precisa selecionar opções elegíveis em 30x e explicar exclusões; página e preparação dos documentos devem consumir essa mesma regra. A lógica atual que ordena por `price.total` e exige `gross_total` não foi alterada por este estudo. A futura mudança também deverá tratar resultados antigos sem dados suficientes e versionar o contrato/snapshot quando necessário.

Validações essenciais futuras: 30x confirmado; opção de 12/24x excluída; quantidade ausente; preço nulo/zero/inválido; meio de pagamento indisponível; IOF/juros discriminados sem soma duplicada; consistência dos retornos com a regra de parcelas iguais; moedas/vigências incompatíveis; empate; aprovação sem oferta de 30x; e seleção idêntica entre página e PDF. Não é necessária chamada remota para registrar esta decisão documental.

## Tokio Marine

**Classificação vigente — suporte confirmado em 28/10/2026 (T20):** não existe encaminhamento para análise via API Tokio. Em uma resposta de negócio válida, `cotacaoInfo.cdSitucCotac = CAL` significa aprovação; qualquer outro código de situação significa recusa. Mensagens são explicativas, não condição adicional para aprovar. Esta confirmação substitui todas as hipóteses históricas de encaminhamento, necessidade de polling e classificação dependente de frases registradas abaixo. Falha técnica ou status ausente/inválido não equivale a um código de recusa.

**Regra vigente — revisão de 27/10/2026:** a resposta do suporte fornecida em `C:\Users\Usuario\Downloads\A API atualmente suporta apenas os.txt` confirma API somente para PF, residencial e não residencial: `tpPessoa = F`, com `icResidencial = S` ou `N`. PJ é exclusivamente via Portal. A seção T15 prevalece sobre orientações históricas de envio de J, validação de suporte comercial e seleção pendente de CNAE. Não converter um lead PJ em PF usando o CPF do responsável para contornar essa restrição.

Consolidação original: **09/10/2026**; revisão: **15/10/2026**. Produto: **Fiança Locatícia**. Estado: catálogo, formulários, persistência e builder parcial de pretendentes implementados localmente; serviço HTTP, provider, payload completo e normalização de resultados Tokio ainda pendentes. Não há comprovação de execução remota da integração nesta revisão.

### T1. Fontes, evidências e limites

Este estudo reúne os materiais enviados e as decisões expressas pelo usuário na conversa:

| Referência | Material | O que fundamenta |
| --- | --- | --- |
| T-01 | Resposta do suporte relatada pelo usuário | Não existe uma collection consolidada de todos os serviços/cenários; foi fornecido um exemplo funcional da etapa de cálculo |
| T-02 | Prints de introdução, URLs, autenticação, Cotação e Recalcular | Ambientes, OAuth, headers e uso de POST `/api/cotar` nas duas operações |
| T-03 | `C:\Users\Usuario\Downloads\Codigo_entrada_cotacao.txt` | Estrutura completa de entrada, com valores `null`; não é um payload executável nem prova que todo campo seja obrigatório |
| T-04 | `C:\Users\Usuario\Downloads\curl --location 'httpsapim-act.toki.txt` | Exemplo preenchido de PF residencial, identificado pelo usuário como mínimo e com dados corretos |
| T-05 | `C:\Users\Usuario\Downloads\Codigo_retorno_cotacao.txt` | Estrutura JSON de retorno; os valores escalares são `null`, sem resultado real preenchido |
| T-06 | `C:\Users\Usuario\Downloads\Parametros_retorno.mp4` | Tabelas visuais de tipos e descrições do retorno, examinadas por quadros; aproximadamente 2min40s. Não foi feita transcrição de áudio |
| T-07 | Prints dos domínios `tipoImovel`, `assistenciaDesejada`, `motivoContratacao`, `ramoAtividade`, `tipoVinculo` | Códigos e descrições de seleção |
| T-08 | Prints posteriores de pagamentos e regras de entrada | Hierarquia de parcelas, campos monetários, headers de recálculo, obrigatoriedade e condições por cenário |
| T-09 | Orientações do usuário ao longo da conversa | Padrões de negócio, comparação por parcelas, destinatários, comportamento pretendido da reanálise e escopo |
| T-10 | Leitura local em 09/10 dos serviços de comparação, preparação, página e helpers do Lead | Pontos de integração e incompatibilidade da comparação atual por total com a nova regra por parcela |
| T-11 | `C:\Users\Usuario\Downloads\geral-tabela-cnae.pdf`, 90 páginas, e derivados versionados em `database/seeders/` | Domínio CNAE, descrição única e preservação de todos os códigos associados |
| T-12 | Print posterior de `estadoCivil` e esclarecimento sobre `pretendentePrincipal` | Códigos de estado civil; principal S, cônjuge N; não existe atributo para CPF cruzado do cônjuge |
| T-13 | Regras posteriores de empresa constituída, vínculo empresarial e nascimento | CNPJ + comercial; referência de fundação de sete anos; Titular/Sócio; idade de 18 a 120 anos completos |
| T-14 | Código e testes da branch verificados em 15/10, incluindo alterações locais ainda não commitadas | Estado efetivo da implementação, independente do planejamento histórico |

Os TXT e o vídeo permanecem nas origens locais; seu conteúdo necessário foi sintetizado aqui, sem copiar credenciais, tokens ou dados pessoais. As prints são fontes desta conversa, não arquivos versionados neste diretório. Não foi fornecido OpenAPI completo nem catálogo completo de erros/status. A documentação fornecida é a fonte do contrato estudado, não uma garantia de comportamento em produção.

Convenções de leitura:

- **Documentado:** consta nos arquivos, vídeo ou prints. Divergências entre materiais são mantidas explícitas.
- **Decisão do sistema:** regra definida pelo usuário, sem atribuí-la à seguradora.
- **Proposta de integração:** orientação técnica para a futura implementação, ainda não executada.
- **Pendente:** falta evidência suficiente; não preencher por adivinhação.
- **Implementado localmente:** comportamento encontrado no código desta branch; não significa que a Tokio já recebeu ou aceitou esses dados.

A skill `api-design` foi aplicada à revisão do contrato externo e das fronteiras de integração. A Tokio fornece uma capacidade de cotação; a página do sistema apresenta uma composição dos resultados. A aplicação deve preservar nomes, URLs e formatos exigidos pela companhia, inclusive datas `DD/MM/AAAA`, sem impor a ela padrões internos de API.

### T2. Contexto do sistema e decisões que prevalecem

1. O cadastro do lead inicia análises automáticas em segundo plano. O objetivo é integrar quatro companhias; Too e Pottencial já têm fluxo, Porto e Tokio aguardam validação. As assistências de uma companhia não são novas companhias nem novos itens de contagem do pacote.
2. Um pacote só termina quando todas as análises participantes chegam a decisão final ou falha. `Pending`/`UnderAnalysis` não significam conclusão. O resultado é aprovado somente com aprovação confirmada de pelo menos uma companhia; recusa unânime exige recusas de todas. Falha técnica não é recusa de crédito.
3. A página de análise é por lead, com pacote atual, progresso, resultados e ações autorizadas. Abrir/atualizar a página consulta o banco; não deve gerar cotação remota. Notificações pequenas por Reverb, canais privados e fila `broadcasts` fazem a página buscar o estado atualizado.
4. **Decisão de preço atualizada em 13/10:** trabalhar com o valor de uma parcela de uma condição de **exatamente 30x**, conforme a regra geral acima. Para Tokio, o valor principal é `vlPremPar` da opção com `qtd = 30`. Condições com outra quantidade não participam da comparação padrão. Isso substitui as premissas anteriores de comparação por total e de “até 30x”; exige futura mudança coordenada da normalização e comparação compartilhadas pela página e documentos.
5. Renda usada pelo sistema: **quatro vezes o aluguel**. Na Tokio, respeitar a condição de envio de `rendaFixaMensal` e não inventar divisão de renda entre pretendentes. Emprego/profissão padrão foi decisão para a Too; esses campos não aparecem na entrada Tokio recebida. O nascimento já é coletado no sistema, mas não deve ser acrescentado ao contrato Tokio sem evidência.
6. Quando o lead é cadastrado com CNPJ, os pretendentes continuam identificados pelo nome/CPF do responsável e, quando aplicável, do cônjuge; o CNPJ da empresa permanece separado. **Decisão posterior do usuário:** enviar `tpPessoa = "J"` para o cenário comercial com CNPJ, além dos dados da empresa constituída. O sistema já deriva `tipo_pessoa = J` pelo documento, mas a documentação Tokio recebida declara somente `F`. Preservar a decisão local e confirmar suporte a `J` antes de habilitar esse envio; não converter silenciosamente a decisão para `F` nem declarar PJ homologada.
7. O LeadLovers recebe o lead após a conclusão com resultado aprovado/recusado. Indisponibilidade dessa integração não pode impedir cadastro, análise ou consolidação local. Falhas de e-mail também não alteram decisões das seguradoras.
8. Documento próprio: após conclusão e havendo aprovação, preparar PDF com detalhes das coberturas da melhor oferta e resumo de preços das demais, mantendo coerência com a seleção da página. Regra histórica de cartas: busca somente na recusa unânime. **Exceção posterior Pottencial (26/10):** consultar a carta de cada plano recusado, mesmo havendo outro aprovado; isso não autoriza entrega global de recusa em resultado misto. Falha técnica não dispara carta de recusa. O PDF próprio permanece desativado até a view ter conteúdo.
9. Entrega por jobs, armazenamento privado e reaproveitamento por rodada. Destinatários: imobiliária vinculada → e-mail principal e setores, sem incluir usuários vinculados; imobiliária não vinculada/proprietário → e-mail do responsável pelo preenchimento; locatário → e-mail do lead. O efeito da nova regra de parcelas no conteúdo final do PDF deve acompanhar a normalização compartilhada.
10. **Reanálise definida em 28/10:** iniciar nova rodada pelos endpoints de nova cotação/solicitação de análise de cada companhia, com os dados vigentes do lead. Não usar endpoints dedicados de reanálise nem recálculo da cotação anterior. Preservar todas as etapas obrigatórias da nova solicitação e o histórico; implementação somente após concluir os estudos e regras de todas as APIs, conforme a seção geral de reanálise.

### T3. Ambientes, autenticação e headers

#### Bases e operações

| Ambiente | Base, já incluindo `/api` |
| --- | --- |
| Aceite W | `https://apim-act.tokiomarine.com.br/massificados/cotador-fianca-service-w/api` |
| Aceite Y | `https://apim-act.tokiomarine.com.br/massificados/cotador-fianca-service-y/api` |
| Produção | `https://apim.tokiomarine.com.br/massificados/cotador-fianca-service/api/` |

Acrescentar `/cotar` à base normalizada, sem duplicar `/api` ou barras. O exemplo T-04 usa Aceite W. A diferença operacional entre W e Y não foi explicada; não fazer fallback automático entre ambientes nem reutilizar credenciais de outro ambiente por suposição.

| Operação documentada | Método e URL/caminho | Observações |
| --- | --- | --- |
| Autenticação | POST `https://apim.tokiomarine.com.br/oauth2/v2.0/token` | URL fornecida também junto ao exemplo de aceite; confirmar permissões das credenciais por ambiente |
| Cotação | POST `{base}/cotar` | Corpo JSON de cotação; não confundir cotação com emissão |
| Recalcular | POST `{base}/cotar` | Mesmo caminho; headers posteriores documentam a identificação da cotação para recálculo |

Não há, no material fornecido, um endpoint separado confirmado de editar cotação, consultar resultado, solicitar análise de crédito ou baixar carta de recusa. O menu mostra Contratação, Biometria e Impressos, mas nenhum caminho dessas seções foi fornecido; não inventar rotas nem utilizá-las como autorização para contratar.

#### Autenticação OAuth

Enviar `Content-Type: application/x-www-form-urlencoded` e os campos:

| Campo | Formato / origem |
| --- | --- |
| `client_id` | Credencial fornecida pela Tokio |
| `client_secret` | Segredo fornecido pela Tokio |
| `scope` | Formato documental `api://<identificador>/.default`; usar o valor autorizado, sem deduzi-lo do client ID |
| `grant_type` | Literal `client_credentials` |

Resposta de exemplo: `token_type = "Bearer"`, `access_token`, **`expire_in`** e `ext_expires_in`, estes últimos com valor exemplificado `3600`. A grafia recebida é `expire_in`, não `expires_in`; confirmar o nome real antes de implementar cache. Não tratar `ext_expires_in` como permissão para usar um token normal vencido. Proposta: cache separado por ambiente/credencial, validade confirmada com margem de renovação e renovação coordenada entre workers. Os cookies mostrados no cURL documental não têm obrigatoriedade comprovada e não devem virar credenciais fixas.

#### Headers da cotação e do recálculo

| Header | Tipo documental | Uso e condição |
| --- | --- | --- |
| `Content-Type` | Header HTTP, presente no cURL | `application/json` |
| `Authorization` | Alfanumérico | `Bearer <access_token>` gerado na autenticação |
| `codigoCorretor` | Numérico | Código do corretor no cadastro Tokio |
| `cpfUsuario` | Alfanumérico | CPF do usuário/operador da requisição, não automaticamente o CPF do lead |
| `senhaOperadora` | Alfanumérico | Chave de criptografia fornecida pela Tokio; segredo de integração |
| `codigoParceiro` | Alfanumérico | Código do parceiro no cadastro Tokio; não é o ID local da imobiliária |
| `tipoParceiro` | Alfanumérico | Obrigatório se `codigoParceiro` for enviado: `5` Imobiliária; `9` Concessionária |
| `sqCdCotac` | Numérico | Número da cotação em questão, explicitamente descrito como usado para recálculo |
| `token` | Alfanumérico | Token de controle de acesso à cotação; diferente do OAuth |

O cURL mínimo envia os quatro headers de negócio `Authorization`, `codigoCorretor`, `cpfUsuario`, `senhaOperadora`, além do Content-Type. Não envia parceiro nem identificadores de recálculo. As prints de headers não trazem coluna geral de obrigatoriedade; não presumir que todos sejam obrigatórios em criação. Para recalcular, conservar `cotacaoInfo.sqCdCotac` e `cotacaoInfo.token` e confirmar as condições exatas de envio/validade.

### T4. Entrada: tipos, cenários e obrigatoriedade

“Alfanumérico” é o rótulo documental, normalmente representado por string; “Valor” não especifica precisão, escala ou aceitação de string decimal. O cURL usa números JSON nos valores monetários e percentuais. CPF, CNPJ e CEP devem preservar zeros iniciais como strings. Indicadores usam `"S"`/`"N"`, não booleanos, quando assim documentados.

O usuário esclareceu que T-04 é o mínimo PF residencial. A estrutura completa com `null` não deve ser enviada literalmente nem usada para tornar todos os campos obrigatórios. O builder futuro separará PF residencial, PF não residencial e opcionais condicionais. Omissão de um opcional não equivale a mandar `null`.

#### Raiz e dados do item

| Campo | Tipo | Regra documental / aplicação |
| --- | --- | --- |
| `tpPessoa` | Alfanumérico | Obrigatório, sempre `F` na API; suporte confirmou em 27/10 que PJ é somente via Portal. Lead PJ segue atendimento manual, conforme T15 |
| `icResidencial` | Alfanumérico | Obrigatório: `S` residencial ou `N` não residencial; ambos os cenários PF confirmados pelo suporte em 27/10 |
| `dadosItem` | Objeto | Obrigatório |
| `dadosLocacao` | Objeto | Obrigatório |
| `pretendentes` | Lista de objetos no JSON | Obrigatório; tabela chama “Objeto”, descrição e exemplos mostram lista. Fragmentos comerciais recebidos em 27/10 separam dados empresariais e pessoa em dois objetos distintos; ver T17 |
| `coberturas` | Objeto | Obrigatório, não array |
| `pcComissao` | Valor | Obrigatório; padrão de negócio confirmado de 15%, enviado como `15` na Tokio e observado no retorno real de T19 |
| `pcRemuneracao` | Valor | Obrigatório; padrão confirmado `0`, futuramente editável em Gerenciar seguradoras |
| `pcDesconto` | Valor | Obrigatório; padrão confirmado `0`, futuramente editável em Gerenciar seguradoras |
| `pcAgravo` | Valor | Obrigatório; padrão confirmado `0`, futuramente editável em Gerenciar seguradoras |
| `dadosItem.tipoImovel` | Alfanumérico na tabela; número no cURL | Obrigatório; seleção definida em T5 |
| `dadosItem.motivoContratacao` | Alfanumérico na tabela; número no cURL | Obrigatório; seleção por cenário definida em T5 |
| `dadosItem.congenere` | Numérico | Opcional; referência à congênere, catálogo não fornecido |

#### `dadosLocacao`

| Campo | Tipo | Regra |
| --- | --- | --- |
| `cep` | Alfanumérico | Obrigatório, CEP do imóvel |
| `endereco` | Alfanumérico | Obrigatório, logradouro |
| `numero` | Alfanumérico | Obrigatório; cURL envia string |
| `bairro` | Alfanumérico | Obrigatório |
| `inicioVigenciaContrato` / `fimVigenciaContrato` | Alfanumérico | Obrigatórios, `DD/MM/AAAA` |
| `inicioVigenciaSeguro` / `fimVigenciaSeguro` | Alfanumérico | Obrigatórios, `DD/MM/AAAA`; separados da vigência do contrato |
| `assistenciaDesejada` | Alfanumérico | Obrigatório; decisão: `26203`, Sem assistência |
| `multiploLMI` | Numérico | Decisão: `30`. Tabela posterior obrigatório, anterior opcional; ausente no cURL mínimo |
| `pinturaNova` | Alfanumérico | S/N; padrão confirmado pelo usuário: S nos cenários com cobertura de pintura interna/externa, preservando a exceção tenant de somente aluguel |
| `complemento` | Alfanumérico | Opcional no material inicial e presente no template de entrada |
| `utilizaContratoPadrao` | Alfanumérico | Opcional, S/N, **depreciado** |
| `cobrancaNoCartao` | Alfanumérico | Opcional, S/N, **depreciado** |

`multiploLMI` possui os valores documentados 12, 18, 24 e 30 meses de limite máximo de indenização. A escolha do usuário é 30. Não confundir essa unidade com a quantidade de parcelas do prêmio. O cURL exemplifica um ano de contrato/seguro, mas a decisão posterior de 28/10 definiu expressamente, para todas as seguradoras, início na data da análise e término 30 meses depois para contrato e seguro. Essa duração decorre da regra de vigência confirmada, não de inferência a partir do LMI ou do parcelamento.

#### `pretendentes[]`

**Escopo dos objetos — evidência posterior em T17:** nos dois fragmentos comerciais recebidos em 27/10, o primeiro objeto contém dados empresariais e não possui CPF, nome ou indicador de principal; o segundo contém a pessoa principal e seu vínculo. As exigências de identificação pessoal abaixo não devem ser aplicadas indiscriminadamente a todo elemento da lista. Os fragmentos são parciais e não demonstram todos os campos obrigatórios da requisição completa.

| Campo | Tipo | Obrigatoriedade e significado |
| --- | --- | --- |
| `cpf` | Alfanumérico | Obrigatório, CPF do pretendente |
| `pretendentePrincipal` | Alfanumérico | Obrigatório, S/N; o CPF principal é o titular segundo a regra do usuário |
| `nome` | Alfanumérico | Obrigatório |
| `residenteNoImovel` | Alfanumérico | Opcional, S/N |
| `respondeFinanceiramente` | Alfanumérico | Opcional no contrato, S/N; regra do sistema confirmada em 28/10: enviar S para ambos os pretendentes do casal, nas respectivas individuais e na conjunta condicional |
| `nomeSocial` | Alfanumérico | Opcional |
| `estadoCivil` | Alfanumérico | Opcional no contrato; domínio recebido posteriormente e registrado em T5. Regras comerciais do builder em T14 |
| `rendaFixaMensal` | Alfanumérico | Tabela marca opcional, descrição condiciona envio a responsabilidade financeira S; regra confirmada: 4 × aluguel para cada pretendente, nas individuais e na conjunta, sem dividir entre o casal |
| `empresaConstituida` | Alfanumérico | S/N; descrito como obrigatório para PF não residencial |
| `cnpj` | Alfanumérico | CNPJ da empresa, obrigatório no cenário PF não residencial conforme descrição; condições para empresa não constituída precisam ser confirmadas |
| `CNAE` | Numérico | Obrigatório para PF não residencial; preservar a grafia maiúscula. Catálogo recebido e importador implementado; enviar somente o código, não a descrição |
| `ramoAtividade` | Numérico | Obrigatório para PF não residencial; seletor de três valores implementado, com lacuna de obrigatoriedade no Request descrita em T14 |
| `dataFundacao` | Alfanumérico | `DD/MM/AAAA`, obrigatório no não residencial com empresa constituída |
| `capitalInicial` | Valor | Usuário esclareceu: somente comercial/não residencial, padrão **450000**; omitir no residencial |
| `capitalGiro` / `capitalSocial` | Valor | Opcionais, sem padrões definidos |
| `franquia` | Alfanumérico | Opcional, S/N, indica se a empresa é franquia |
| `franqueadora` | Numérico | Opcional, código da franqueadora; domínio não recebido |
| `vinculoEmpresa` | Alfanumérico | S/N; obrigatório para PF não residencial conforme descrição |
| `tipoVinculo` | Numérico na tabela original; string nos fragmentos T17 | Obrigatório para PF não residencial; representa o vínculo com a empresa. Os dois novos exemplos enviam `"21149"` (Sócio) na pessoa principal |
| `percentualParticipacao` | Número nos fragmentos T17 | Campo observado na pessoa principal, valor `100` nos dois cenários; não confundir com comissão ou com a escala fracionária da participação na Pottencial. Obrigatoriedade geral e divisão entre múltiplas pessoas não demonstradas |

A regra foi detalhada e implementada para **CNPJ + comercial + solteiro/casado/união estável**: principal como Titular; nos dois últimos estados, cônjuge como Sócio. Trata-se da regra operacional definida pelo usuário, não de uma comprovação cadastral de participação societária. O esclarecimento posterior substitui a ideia de CPF cruzado: **não existe atributo para receber o CPF do cônjuge dentro do outro pretendente**. Cada objeto recebe seu próprio `cpf`, com `pretendentePrincipal = "S"` no principal e `"N"` no secundário. Não colocar CPF em `estadoCivil`. A composição de renda/responsabilidade financeira continua distinta dessa identificação. Ver limites do builder em T14.

#### `coberturas`

| Campo | Tipo | Regra / significado |
| --- | --- | --- |
| `vlAluguel` | Valor | Obrigatório, valor do aluguel; exemplo numérico `400.00` |
| `icDanosImovel` | Alfanumérico | Opcional, S/N |
| `icPinturaInterna` | Alfanumérico | Opcional, S/N |
| `icPinturaExterna` | Alfanumérico no exemplo como S/N | Presente no template e cURL, ausente da última tabela resumida; confirmar condições |
| `icMultaRescisao` | Alfanumérico | Opcional; template/cURL comentado exemplificam S/N |
| `vlDespesasOrdCondominiais` | Valor | Opcional, despesas condominiais; manter esta grafia exata |
| `vlIPTU` | Valor | Opcional, valor mensal |
| `vlAgua` | Valor | Opcional, valor mensal |
| `vlLuz` | Valor | Opcional, valor mensal |
| `vlGasCanalizado` | Valor | Opcional, valor mensal |

Não acrescentar encargos/coberturas fictícios. Ausência de um campo na última print não revoga automaticamente sua presença nos outros materiais. Pintura interna/externa estão ativadas no exemplo, mas não houve decisão expressa de ativá-las incondicionalmente em todos os leads.

### T5. Domínios recebidos e padrões aprovados

#### `dadosItem.tipoImovel`

| Código | Descrição | Uso definido pelo usuário |
| --- | --- | --- |
| 10000 | Apartamento | Apenas referência do exemplo recebido |
| 10001 | Casa | **Padrão residencial** |
| 10002 | Casa em condomínio | Sem seleção definida |
| 21104 | Sala comercial em condomínio | Sem seleção definida |
| 21103 | Sala comercial | **Padrão não residencial** |
| 21105 | Loja | Sem seleção definida |
| 21106 | Loja em galeria comercial | Sem seleção definida |
| 21107 | Loja em small centers | Sem seleção definida |
| 21108 | Loja de Shopping | Sem seleção definida |
| 21109 | Prédio | Sem seleção definida |
| 21110 | Prédio em condomínio | Sem seleção definida |
| 21111 | Galpão | Sem seleção definida |
| 21112 | Galpão em condomínio | Sem seleção definida |

#### `dadosLocacao.assistenciaDesejada`

| Código | Descrição | Decisão |
| --- | --- | --- |
| 26203 | Sem assistência | **Enviar no sistema** |
| 26204 | Assistência Básica | Referência |
| 26205 | Assistência Intermediária | Referência |
| 26206 | Assistência Completa | Usado no cURL original, substituído pela decisão 26203 |

#### `dadosItem.motivoContratacao`

| Cenário | Código | Descrição | Decisão |
| --- | --- | --- | --- |
| PF residencial, domínio atual | 26470 | Seguro Novo | **Usar** |
| PF residencial, domínio atual | 26472 | Troca de Garantia | Referência |
| PF residencial, domínio atual | 10013 | Renovação congênere | Referência |
| PF residencial, domínio atual | 26212 | Renovação Tokio Marine | Referência |
| PF não residencial | 21113 | Instalações da empresa | Referência |
| PF não residencial | 21114 | Troca de local de sede | **Usar** |
| PF não residencial | 21115 | Abertura de filial (expansão) | Referência |
| PF não residencial | 10013 | Renovação congênere | Referência |
| PF não residencial | 26212 | Renovação Tokio Marine | Referência |

Domínios antigos PF residencial, preservados apenas para interpretar materiais: 10003 Casamento; 10004 Separação; 10005 Independência; 10006 Venda do imóvel próprio; 10007 Redução do custo familiar; 10008 Adequação do imóvel à família; 10009 Proximidade ao local de trabalho; 10010 Locação para terceiros; 10013 Renovação congênere; 26212 Renovação Tokio Marine. O cURL usa 10008; o builder futuro deverá seguir **26470**, conforme decisão posterior, sem presumir que o exemplo prove aceitação atual de todos os códigos antigos.

#### `pretendentes[].ramoAtividade`

Seletor de formulário já implementado em `business-activity.blade.php`, aplicável somente a `tipo_locacao = comercial`:

| Código | Descrição |
| --- | --- |
| 21205 | Comércio |
| 21204 | Indústria |
| 21206 | Serviços |

Não substituir ramo de atividade por CNAE; são campos distintos. A seleção já é validada pelo enum `RamoAtividade` e persistida em `leads.ramo_atividade`. O HTML exige escolha, mas o Request ainda aceita ausência/null no comercial; essa tolerância era transitória antes da inclusão do seletor e precisa ser revista antes de enviar à Tokio.

#### `pretendentes[].tipoVinculo`

| Código | Descrição | Regra fornecida pelo usuário |
| --- | --- | --- |
| 21150 | Acionista | Sem uso definido |
| 21215 | Representante legal | Sem uso definido |
| 21149 | Sócio | Cônjuge no cenário comercial com CNPJ e casado/união estável definido em T14 |
| 21147 | Titular | CPF principal, com ou sem cônjuge |

#### `pretendentes[].estadoCivil`

Domínio da print posterior, com repetições idênticas removidas desta tabela:

| Código | Descrição documental |
| --- | --- |
| 18485 | Solteiro(a) |
| 18486 | Divorciado(a) |
| 18487 | Desquitado(a) |
| 18488 | Separado(a) |
| 18489 | União estável |
| 18490 | Casado(a) |
| 21284 | Marital |
| 21285 | Viúvo(a) |
| 19901 | Não Informado |

**Decisão expressa do usuário para o cenário empresarial implementado:** solteiro usa `"18485"`; casado **e união estável** usam `"18490"` em ambos os pretendentes. O código documental `18489` continua registrado, mas não substitui essa decisão no builder comercial. Outras companhias recebem descrições conforme seus contratos; manter `União estável` nos dados internos, sem regravar o estado civil do lead como casado apenas por causa do mapeamento Tokio.

### T6. O que o exemplo preenchido comprova — e o que não comprova

T-04 é uma chamada de **PF residencial no Aceite W**. Estrutura observada, sem reproduzir dados pessoais ou segredos:

- Raiz: `tpPessoa = "F"`, `icResidencial = "S"` e os quatro percentuais numéricos.
- Item: `tipoImovel = 10000`, `motivoContratacao = 10008`, ambos números no cURL.
- Locação: endereço, datas `08/10/2026` a `08/10/2027` para contrato e seguro, `assistenciaDesejada = "26206"`, `pinturaNova = "S"`.
- Um pretendente: CPF em string, `pretendentePrincipal = "S"`, nome. Não há renda, nascimento, profissão ou dados empresariais.
- Coberturas ativas: `vlAluguel = 400.00`, `icPinturaInterna = "S"`, `icPinturaExterna = "S"`.
- Percentuais: comissão 15; remuneração, desconto e agravo 0. São exemplos; não equivalem a configuração comercial aprovada para a integração Tokio.
- Condomínio, IPTU, água, luz, gás, danos e multa estão em linhas comentadas. O corpo enviado literalmente por esse cURL contém `//` e **não é JSON estrito válido**; remover comentários antes de executar. Valores em comentários não são prova de campo efetivamente enviado nem de tipo aceito.

Não copiar datas, identidade, imóvel ou credenciais do exemplo para produção. As decisões posteriores prevalecem: Casa 10001, Seguro Novo 26470, Sem assistência 26203 e LMI 30. Adicionar esses padrões produz um payload adaptado ao sistema, ainda a validar, não uma reprodução da chamada original. O fato de o usuário identificar o exemplo como mínimo não resolve sozinho as divergências das tabelas, especialmente LMI.

Na revisão original não havia resposta preenchida dessa execução. A print posterior de 27/10/2026, registrada em T16, mostra uma execução no Aceite W com HTTP 200, identificador de cotação, `cdSitucCotac = CAL` e a mensagem explícita `Cadastro aprovado.`. Essa evidência não inclui o corpo completo de pagamentos nem comprova os demais cenários.

### T7. Retorno: identificação, estado e alternativas

#### Raiz e `cotacaoInfo`

| Campo | Tipo documental / formato observado | Uso |
| --- | --- | --- |
| `cotacaoInfo` | Objeto | Informações da cotação |
| `cotacaoInfo.sqCdCotac` | Numérico | Identificador remoto; conservar sem arredondamento/perda de precisão |
| `cotacaoInfo.cdSitucCotac` | Alfanumérico | Regra confirmada em T20: `CAL` = aprovação; qualquer outro código retornado = recusa. Não exigir mensagem específica nem limitar recusas a REC |
| `cotacaoInfo.cdNgocoRsvdo` | Numérico | Código de negócio reservado; sem papel adicional confirmado |
| `cotacaoInfo.dtPrmroCallo` | Alfanumérico | Data do primeiro cálculo; preservar a grafia `dtPrmroCallo` |
| `cotacaoInfo.dtVldad` | Alfanumérico | Validade da cotação; não é vigência do seguro |
| `cotacaoInfo.token` | Alfanumérico | Token da cotação, confidencial; não é access token OAuth |
| `cotacaoInfo.abaInicial` | Objeto | `id` numérico, `descricao` e `rota` alfanuméricos; metadados de navegação, sem necessidade na página própria |
| `mensagens` | Objeto | T19 confirma listas de strings em `success`, `warning` e `danger`. O recusado também contém success; classificar pelo conteúdo específico e estado, não pela presença da categoria |
| `calculos` | Objeto no JSON | A tabela de raiz o chama alfanumérico, incompatível com o exemplo e subtabelas |
| `ordemApresentacaoCalculos` | Array de strings nos retornos T19 | `["N"]` no aprovado e `[]` no recusado; corrige o rótulo alfanumérico da tabela original. Não há garantia de ordenação por preço |
| `pcComissao`, `pcProLabore`, `pcDesconto`, `pcAgravo` | Valor | Percentuais retornados; entrada usa `pcRemuneracao`, saída usa `pcProLabore`, sem equivalência detalhada confirmada |

HTTP bem-sucedido, mera presença de `mensagens.success` e presença de valores não provam aprovação de crédito. **Regra final do suporte em T20:** classificar pelo código de situação da resposta válida: CAL aprova; qualquer outro código recusa. As mensagens de T19 explicam o resultado, inclusive success operacional no recusado, mas não substituem nem condicionam essa regra. Se o texto divergir do código, preservar a divergência para diagnóstico, mantendo a classificação pelo código. Não criar estado de encaminhamento nem polling para essa API.

#### Alternativas de assistência em `calculos`

| Chave | Referência histórica das tabelas, sujeita à identificação no retorno |
| --- | --- |
| `P` | Assistência Completa |
| `N` | Assistência Básica |
| `O` | Assistência Intermediária — letra O, não zero |

**Correção por evidência real em T19:** o retorno de 27/10 contém somente a chave `N`, com `tpSugestao = N` e `opcaoAssistencia = "Plano Completo"`. Portanto, não deduzir assistência Básica apenas da letra N, como sugeria a interpretação anterior. Preservar chave, descrição recebida e assistência solicitada; a tabela histórica acima não pode ser um mapa fixo que sobrescreve o retorno real. Não inferir a identidade de P/O a partir desse único caso. Não somar alternativas nem contá-las como companhias distintas.

**Pendência de compatibilidade:** a entrada final definida é Sem assistência 26203; o diagnóstico de T19 usou Completa 26206 e retornou N/Plano Completo. Isso não define a representação de Sem assistência nem autoriza trocar o padrão do projeto. Conferir a identificação com o payload final, preservando a escolha já aprovada.

#### Campos de cada `calculos.{P|N|O}`

| Campo | Tipo documental | Uso |
| --- | --- | --- |
| `tpSugestao` | Alfanumérico | Tipo da sugestão |
| `opcaoAssistencia` | Alfanumérico | Descrição da assistência |
| `opcoesPagamento` | Objeto | Condições de pagamento; grafia singular `Pagamento` |
| `coberturas` | Lista de objetos no JSON | Coberturas da alternativa |
| `diferenciais` | Lista de objetos no JSON | Cada item possui `descricao` alfanumérica |
| `sugestaoValorTotal` | Valor | Total da sugestão, sem composição bruta/líquida confirmada |
| `sugestaoPagto` / `sugestaoCobranc` | Alfanumérico | Referências de pagamento e cobrança da sugestão |
| `sugestaoQtdParc` | Valor | Quantidade sugerida; rótulo documental é Valor, não Numérico |
| `sugestaoJuros` | Alfanumérico | Juros da sugestão; formato real ainda desconhecido |
| `sugestaoValorParc` | Valor | Parcela sugerida; não substitui automaticamente `vlPremPar` da condição escolhida |
| `valorLMG` | Valor | Valor LMG, limite de garantia; não é preço |
| `descSugestaoPagto` / `descSugestaoCobranc` | Alfanumérico | Descrições para apresentar a condição sugerida |

T-05 era um template com escalares null; T19 acrescenta tipos e valores observados em retornos reais. Manter a diferença entre ausente, null, string vazia e zero. Não anunciar seguro gratuito ou melhor preço por ausência de valor.

### T8. Pagamentos e comparação por parcela

#### Hierarquia exata

```text
calculos
  {P|N|O}
    opcoesPagamento
      {debito|credito|ficha|faturado}
        {vista|prazo}
          {chave da opção, exemplificada por "1"}
            qtd
            pcJur
            vlPremTot
            vlJurTot
            vlIofTot
            vlPremPar
            vlJurPar
            vlIofParc
      codFormaPagamento
      codFormaCobranca
      qtdParcela
```

As tabelas iniciais mostravam somente a chave `1`; T19 comprova múltiplas opções com chaves 1 a 12 no diagnóstico de um ano. Elas são alternativas de quantidade, não parcelas sucessivas de um calendário. A regra final reafirmada pelo usuário é **limite de 30 e uso de 30 parcelas**; o exemplo diagnóstico não redefine esse limite. Selecionar pela propriedade `qtd`, não pelo nome da chave. O agrupamento `vista` desse retorno contém opções parceladas, portanto não significa automaticamente uma única parcela; a intenção final de pagamento parcelado permanece e a representação vista/prazo deve ser conferida no retorno do payload final.

| Campo da opção | Tipo documental | Significado e uso |
| --- | --- | --- |
| `qtd` | Numérico | Quantidade; associar ao preço selecionado e validar como quantidade de parcelas |
| `pcJur` | Valor | Percentual de juros |
| `vlPremTot` | Valor | Prêmio total da condição |
| `vlJurTot` | Valor | Juros totais |
| `vlIofTot` | Valor | IOF total |
| **`vlPremPar`** | **Valor** | **Prêmio da parcela: valor principal escolhido pelo usuário para card e comparação Tokio** |
| `vlJurPar` | Valor | Juros da parcela |
| `vlIofParc` | Valor | IOF da parcela; grafia termina em `Parc` |

Em `opcoesPagamento`, `codFormaPagamento` e `codFormaCobranca` são alfanuméricos; `qtdParcela` é numérico. Guardar os metadados, mas não substituir o `qtd` da opção escolhida por um valor geral incompatível. Divergência precisa ser explicitada.

#### Seleção definida pelo solicitante

| Solicitante | Forma | Modalidade | Situação |
| --- | --- | --- | --- |
| Cotação com imobiliária presente | `faturado` como padrão das cotações de imobiliárias | `prazo` | Revisão de 23/10: fatura exige imobiliária efetivamente informada; confirmar opção em 30x |
| Sem imobiliária, inclusive locatário ou proprietário | `credito` | `prazo` | Revisão de 23/10: crédito obrigatório; substitui a alternativa anterior débito/crédito |

`ficha` e `vista` constam no contrato, mas não foram escolhidos para o fluxo. A descrição de `ficha` não basta para afirmar equivalência com boleto. Não fazer fallback silencioso de faturado para crédito/débito caso a API não retorne a forma exigida. A regra de destinatário do e-mail não resolve a forma de pagamento.

#### Regra de apresentação e seleção futura

1. Manter assistência, forma, modalidade, chave, quantidade e vigência vinculadas a cada preço; nunca combinar parcela de uma opção com coberturas de outra.
2. Usar o `vlPremPar` confirmado da condição elegível; não usar o menor número encontrado em qualquer parte da resposta. `sugestaoValorParc`, `valorPremio` de cobertura, LMG e IS não são substitutos automáticos.
3. Exibir “30x de R$ ...” somente para a opção confirmada de 30 parcelas. A periodicidade mensal foi confirmada pelo usuário: uma parcela por mês durante 30 meses. Não dividir arbitrariamente o total por 30. Condições de outra quantidade podem ser informadas como resultado, sem participar do ranking padrão.
4. A decisão atual é comparar **parcelas de condições de exatamente 30x**. Ofertas com menos ou mais parcelas ficam fora desse ranking; vigências, coberturas e bases monetárias precisam ser compatíveis conforme a regra geral. Não apresentar a menor parcela como prova de menor custo total. Manter total e encargos disponíveis para contexto.
5. O usuário confirmou que `vlPremPar`, como valor principal da parcela Tokio, já inclui IOF, juros e demais encargos aplicáveis. Não adicionar `vlJurPar`/`vlIofParc` novamente. Preservar `vlPremTot` e os valores discriminados separadamente; essa confirmação sobre a parcela não autoriza recalcular ou reinterpretar os totais.
6. Não afirmar que `qtd × vlPremPar = vlPremTot`; arredondamentos, entrada ou estrutura de cobrança não foram documentados. Conservar valores reportados sem fabricar uma reconciliação.
7. Destacar definitivamente a melhor proposta somente após conclusão do pacote, com aprovação confirmada, preço positivo e condições comparáveis. Pendente, recusada, falha ou sem preço não entram como vencedoras.
8. Documento e página devem usar a mesma seleção. A disponibilidade de um preço não deve alterar o status de crédito, e uma falha de comparação não deve converter aprovação em recusa.

### T9. Coberturas e dados úteis ao card/PDF

Para cada item de `calculos.{opcao}.coberturas[]`:

| Campo | Tipo documental | Destino recomendado na futura normalização |
| --- | --- | --- |
| `codigo` | Numérico | Identificação da cobertura |
| `descricao` | Alfanumérico | Nome a apresentar |
| `valorPremio` | Valor | Prêmio da cobertura; não presumir que seja parcela nem que seja bruto |
| `valorIS` | Valor | Importância segurada, separada do preço |
| `valorVerba` | Valor | Verba informada; confirmar semântica/periodicidade ao comparar coberturas |
| `caracteristica` | Alfanumérico | Detalhe da cobertura |
| `textoFranquia` | Alfanumérico | Condição de franquia |
| `textoFranquiaComplementar` | Alfanumérico | Complemento de franquia |

O card deverá receber nome da alternativa, situação, valor da parcela, quantidade e forma de pagamento, com total/encargos e cobertura disponíveis para detalhamento. `diferenciais[].descricao` pode ser exibido como complemento, sem participar do cálculo de preço. Tratar textos externos como texto, sem renderizar HTML não confiável.

Não somar `valorPremio` das coberturas e chamar o resultado de total bruto sem confirmação. `valorIS`, `valorVerba`, `valorLMG` e `multiploLMI` têm finalidades diferentes do prêmio e não competem como “melhor preço”.

`abaInicial` e a `rota` da Tokio não são navegação da aplicação local. Não expor tokens, credenciais, resposta integral ou dados pessoais desnecessários no contrato da página/broadcast. Percentuais de comissão/remuneração não são critérios diretos de ranking.

### T10. Criação, recálculo, reanálise e tratamento de resultados

| Capacidade desejada | Evidência Tokio | Consequência |
| --- | --- | --- |
| Criar orçamento | POST `/cotar` com entrada completa do cenário | Implementável após fechar validações e interpretar resposta real |
| Solicitar análise | POST `/cotar` retorna a decisão cadastral, conforme confirmação T20 | Não existe encaminhamento via API; não inventar `/analisar` ou uma segunda etapa de decisão |
| Visualizar resultado | Resposta de `/cotar` tem situação e cálculos | Classificar CAL como aprovado e outro código como recusado; não aguardar consulta periódica de encaminhamento |
| Recalcular orçamento existente | POST `/cotar`, com header `sqCdCotac` descrito para recálculo e `token` de acesso à cotação | Referência documental; excluído do fluxo de reanálise pela decisão de 28/10 |
| Nova rodada de análise | Decisão definitiva de criar nova cotação por POST `/cotar` | Usar dados vigentes e preservar histórico; não enviar headers de identificação da cotação antiga |
| PDF/carta de recusa | Menu Impressos, sem contrato fornecido | Dependência documental pendente; não confundir cotação/proposta PDF com carta de decisão |

Sequência proposta para integração, não executada: validar cenário e dados → fixar snapshot da rodada → autenticar → cotar → validar envelope/identificação/estado → persistir resultado apenas se a rodada ainda for atual → normalizar alternativas/preços → notificar a página após commit → consolidar pacote quando todos forem terminais → acionar entregas/integradores independentemente.

Preservar proteções existentes de `attempt_id`, histórico e concorrência. Um recálculo/nova cotação tardio não pode sobrescrever outra rodada. Reanálise de negócio (novos dados/nova decisão), recálculo remoto (mesma cotação) e retry técnico (falha de transporte) são ações distintas.

Não há política documentada de idempotência, rate limit, webhooks ou polling. Timeout pode ocorrer depois de a cotação ter sido criada; repetir o POST pode duplicá-la. Não inventar suporte a `Idempotency-Key`. Reenfileiramento deve respeitar a possibilidade de resultado remoto desconhecido, os limites da fila e o contrato que vier a ser confirmado. Deletar um registro local de teste não apaga uma cotação na Tokio.

#### HTTP, falhas e status de negócio

Os materiais não enumeram respostas HTTP nem catálogo de erros. A tabela abaixo é uma proposta defensiva para o cliente, **não documentação de códigos garantidos pela Tokio**:

| Resposta observada futuramente | Tratamento proposto |
| --- | --- |
| 2xx com JSON | Validar estrutura e situação; sucesso de transporte não significa aprovação |
| HTTP 202 inesperado sem decisão de negócio válida | Tratar como resposta insuficiente/fora do contrato confirmado, não inventar encaminhamento ou polling. O número HTTP não substitui `cdSitucCotac` |
| 204, corpo vazio ou 2xx sem dados indispensáveis | Resultado insuficiente, sem fabricar cotação/preço |
| 3xx | Não seguir automaticamente redirecionamento para host não autorizado com credenciais |
| 400/422 | Registrar falha de validação/negócio retornada; não repetir payload inválido sem correção |
| 401 | Verificar token/configuração; renovação limitada, sem loop, e sem repetir criação com efeito incerto |
| 403 | Falha de permissão/credencial/ambiente; não tratar como recusa do pretendente |
| 404/409 e outros 4xx | Preservar status e mensagem; investigar endpoint/identificador/conflito, sem classificação de crédito por HTTP |
| 429 | Respeitar orientação de espera quando presente, com retry limitado somente se seguro |
| 5xx, timeout, conexão interrompida | Falha técnica ou resultado desconhecido; não transformar em recusa e não repetir POST cegamente |
| HTML, texto inesperado ou JSON inválido | Erro de integração; preservar diagnóstico protegido, sem repassar conteúdo bruto à página |

Usar tratamento para qualquer código recebido, sem lista fechada que descarte respostas desconhecidas. A proteção local contra execução duplicada é necessária, mas não prova idempotência da operação remota.

### T11. Aplicação no sistema existente — próximos pontos de trabalho

Verificação inicial em 09/10, atualizada em 15/10/2026 nos componentes abaixo; não representa homologação remota:

| Componente existente | Observação / adaptação futura |
| --- | --- |
| `app/Models/Lead.php` | Já possui `rentalApplicantDocument()`, `rentalApplicantCpf()` e `rentalApplicantNameForCpf()` para distinguir CNPJ e responsável. Reaproveitar identidade consistente, sem trocar só CPF e manter nome da empresa |
| `app/Services/Insurance/Providers/InsuranceProviderResolver.php` e `InsuranceProviderInterface.php` | Ponto existente para resolução/contrato dos providers; inserir Tokio no padrão vigente quando autorizada, sem desviar os fluxos de Too/Pottencial |
| `app/Services/Insurance/Payloads/` | Já existe `TokioCompanyApplicantsPayloadBuilder`, restrito aos pretendentes empresariais de T14. Ainda falta builder completo de cotação, incluindo PF residencial, CNAE, percentuais, vigências, coberturas e validação final |
| `app/Services/Insurance/AnalysisQuoteSummary.php` | Atualmente distingue Too e um ramo alternativo com campos da Pottencial. Tokio exige normalização explícita de `calculos`, pagamento e cobertura. Não deixá-la cair nesse ramo genérico |
| `app/Services/Insurance/AnalysisQuoteComparison.php` | **Atualizado em 29/10:** compara parcelas confirmadas nas novas rodadas, preservando o tratamento legado por total; exige valores e períodos comparáveis. A normalização específica Tokio permanece pendente |
| `app/Services/Insurance/InsuranceAnalysisPageService.php` | Compartilha normalização/comparação e só destaca melhor oferta após pacote concluído. Preservar leitura local, autorização e motivos de indisponibilidade |
| `app/Services/Insurance/AnalysisResultPreparationService.php` | Também usa comparação compartilhada e registra preparação por rodada. Seleção de parcela/assistência deve permanecer coerente com a página e com os dados congelados do envio |
| Jobs de execução/conclusão e `InsuranceAnalysisAttempt` | Preservar fila de análises, proteção de rodada, falhas finais e eventos após commit. Só criar sincronização Tokio quando existir operação documentada para ela |
| Formulários e edição do lead | Ramo, catálogo de atividades, constituição e vínculos já possuem estruturas locais. Usar as regras e limitações de T14; a data de fundação calculada é referência por decisão do usuário, não data cadastral comprovada |

`AnalysisQuoteSummary` atualmente espera datas ISO ao normalizar. A entrada Tokio usa `DD/MM/AAAA`, enquanto o formato real das datas de retorno não foi confirmado. A normalização futura deverá validar e converter os formatos conhecidos; não usar `dtVldad` como final da vigência.

A futura representação compartilhada precisa conservar, além de identificação/status/coberturas: código e descrição da assistência, forma e modalidade de pagamento, chave da opção, quantidade, prêmio por parcela, total, juros/IOF separados, moeda e vigência. Esses conceitos são requisitos de dados, não novos nomes de campos impostos à API. Não colocar `vlPremPar` em `gross_premium` ou `price.total` apenas para contornar o comparador atual. Alterar o contrato interno de forma coordenada, incluindo versão dos snapshots se necessário.

A resposta fornecida não tem moeda explícita. BRL pode ser configuração de negócio, mas não deve ser apresentado como campo retornado pela Tokio. Valores monetários devem ser normalizados com precisão decimal e validação, preservando diferença entre ausente, inválido e zero.

### T12. Pendências priorizadas para implementação correta

| Prioridade | Questão / evidência | Impacto e próximo passo |
| --- | --- | --- |
| Encerrada em 28/10 | Suporte confirmou somente aprovação ou recusa via API, sem encaminhamento | CAL aprova; qualquer outro código de situação recusa. Mensagens explicam a decisão. Não falta exemplo de encaminhamento nem mecanismo para acompanhá-lo |
| Alta | Sem assistência 26203 versus diagnóstico Completa 26206/N/Plano Completo | Validar a representação da escolha final. Corrigir a inferência histórica fixa N = Básica |
| Alta | Comparação atual por total versus nova decisão por parcela em exatamente 30x | Refatorar regra compartilhada posteriormente; quantidade e exclusão de outras condições já definidas em 13/10. Confirmar equivalência monetária/vigências/coberturas, formas conforme presença de imobiliária e desempate para PDF |
| Resolvida em 27/10 | Suporte confirmou PF residencial e não residencial | Enviar `tpPessoa = F` e `icResidencial = S/N`; validar payload e retornos durante o desenvolvimento |
| Resolvida em 27/10 | PJ disponível exclusivamente via Portal | Não chamar API Tokio para lead PJ; apresentar orientação manual conforme perfil, sem classificar como recusa ou falha |
| Resolvida em 27/10 | Usuário autorizou qualquer código da mesma descrição | Selecionar um código ativo Tokio com ordenação estável, enviar um número e preservar a relação 1:N; ver T15 |
| Alta para comercial | Ramo obrigatório no HTML, nullable no Request; outros estados civis e comercial com CPF fora do builder empresarial | Fechar obrigatoriedade e cobertura de cenários no payload completo, com falha explícita para dados insuficientes. As regras S/N e Titular/Sócio dos cenários já definidos não estão mais em aberto |
| Composição da parcela resolvida | Inclusão de juros/IOF em `vlPremPar` confirmada pelo usuário para o valor final da parcela | Usar diretamente, sem soma duplicada; preservar formato, arredondamentos e totais retornados, sem inventar total final |
| Validação técnica do payload final | Limite de 30 e uso de 30 parcelas reafirmados pelo usuário em 27/10; exemplo diagnóstico de um ano retorna até 12 | Manter regra 30x, sem criar novo teto ou pedir confirmação da quantidade. Validar identificação da opção e agrupamento vista/prazo ao executar o payload final; não extrapolar preço de 12x |
| Decisão encerrada em 28/10 | Reanálise usa nova cotação, não recálculo | Criar por POST `/cotar` sem headers da cotação anterior; estudar detalhes do recálculo não é mais requisito desta funcionalidade |
| Média | Tabelas divergem de exemplos em tipos e obrigatoriedade | Validar LMI, pintura, capital inicial e códigos numéricos/string. Regra local LMI 30 e capital 450000 só comercial está definida, mas não substitui validação remota |
| Média | Token tem `expire_in` no exemplo | Confirmar resposta OAuth real, validade/cache e segregação W/Y/produção |
| Média | Sem HTTP/errors/idempotência documentados | Obter retornos reais e orientação da Tokio para timeout, repetição, rate limit e consulta/reconciliação |
| Média | PDFs/recusas sem endpoints recebidos | Obter contrato das cartas somente para o fluxo de recusa unânime; não bloquear resultado local por ausência do documento |
| Média | Aplicar e validar os padrões de produção já definidos | Comissão 15%, percentuais adicionais zero, coberturas por origem e vigência de contrato/seguro da data da análise até 30 meses depois estão definidos nas regras gerais. Os fixtures não substituem essas decisões |

### T13. Registro da verificação original e validações futuras

Na entrega original de 09/10: leitura dos templates locais, exemplo cURL já fornecido, extração visual do vídeo na etapa anterior, revisão das prints e decisões então recebidas e conferência pontual dos componentes locais de comparação. Templates de entrada/retorno são JSON parseáveis; o cURL contém comentários no body, registrados como limitação. Nenhum dado sensível foi transcrito para o estudo.

Naquela entrega não foram executadas chamadas, cotações, jobs, migrations nem testes de integração. As implementações posteriores da branch estão registradas em T14; não confundir esse histórico com ausência de desenvolvimento local. Porto permanece fora desta revisão.

Quando a implementação for autorizada, concentrar a validação nos cenários que exercitam o contrato: payload mínimo PF residencial adaptado às regras aprovadas; PF não residencial elegível/incompleto; responsável por CNPJ; múltiplos pretendentes; ausência/expiração de token; status aprovado/recusado/pendente/desconhecido; alternativas e pagamentos ausentes; seleção de exatamente 30 parcelas e exclusão de outras quantidades; valores nulos/zero/inválidos; falha HTTP/timeout sem duplicação; rodada obsoleta; e seleção idêntica na página e no PDF. Testes reais devem usar dados/ambiente autorizados e não acionar LeadLovers ou contratação por efeito colateral.

O estudo está consolidado para orientar o desenvolvimento. Pendências documentais permanecem explícitas e não devem virar pressupostos silenciosos no builder, no status ou no ranking de preços.

### T14. Consolidação da branch e esclarecimentos posteriores — 15/10/2026

Esta seção orienta a próxima implementação junto de T3–T12. O escopo desta atualização é documental: preservar regras aprovadas, registrar o código existente e distinguir pendências remotas de tarefas locais. O exemplo cURL continua sendo o **mínimo PF residencial**, não um modelo comercial completo nem uma lista de todos os campos obrigatórios em todos os cenários.

#### Histórico efetivamente encontrado

| Referência na branch | Entrega relevante |
| --- | --- |
| `1aa8e85` — satisfazendo-api-tokio | Preparação de dados e catálogo no fluxo da branch |
| `fbb188f2c825fe847d9068f435a2ef947e57357e` — campo-ramo-atividade | Seletor de ramo; o título observado no Git é este, embora tenha sido referido na conversa como refatoração da descrição |
| `113bca5` — refatoracao-campo-descrever-atividade | Campo de atividade vinculado ao catálogo, com pesquisa e seleção por ID |
| `55aa2ac` — mvc_campos_tpPessoa_empresa-const_data_fund | Estrutura interna de tipo de pessoa, constituição e data calculada |
| `440723b` — front-end-campo-empresa-constituida | Campo condicional e modal de impedimento |
| `bf6782e` — envio-interno-tipo_vinculo-estadoCivil | Persistência dos vínculos, serviço de pretendentes e builder parcial Tokio |
| `a72f029` — remocao-pesquisa-codigo-atividade-comercial | Pesquisa da atividade somente por descrição, preservando a relação com os códigos |
| Árvore de trabalho, posterior a `a72f029` | Validação compartilhada de nascimento, idade de 18 a 120 anos completos e limites nos formulários; ainda não commitada no momento desta revisão |

#### Catálogo CNAE: armazenamento, integridade e importação

**Decisão fechada:** a Tokio recebe somente o código em `pretendentes[].CNAE`; outras companhias que exigem atividade descrita recebem a descrição selecionada, conforme seus contratos. O solicitante não deve conhecer/digitar o código nem criar uma atividade livre.

- Origem: `geral-tabela-cnae.pdf`, com 90 páginas. O catálogo versionado é [`database/seeders/tokio_cnae.csv`](../database/seeders/tokio_cnae.csv), UTF-8, cabeçalho `descricao,codigos_cnae`.
- São **1.910 descrições únicas e 2.230 códigos distintos**. Códigos da mesma descrição ficam separados por `|` na célula do CSV; esse agrupamento é formato de importação, não payload da API.
- A deduplicação ignora diferenças de caixa, preservando a primeira grafia da descrição, os acentos e todos os códigos. Não reduzir o catálogo a um único código por descrição. A normalização de busca sem acentos é separada da chave de unicidade.
- O teste `tests/Unit/TokioCnaeCatalogTest.php` registra a conferência anterior do mapeamento completo, incluindo **13 descrições que atravessam páginas**. Essa referência deve ser revista quando houver novo PDF, não atualizada apenas para fazer o teste passar.
- Metadados em [`tokio_cnae.csv.source.json`](../database/seeders/tokio_cnae.csv.source.json): nome da origem, SHA-256 do PDF e CSV e as duas contagens. Hash do PDF: `d0f39bf5957379aba1890450a21a37b1c891c58b313de204f8a9a2e2b230895f`; hash do CSV: `d8f35cf9a52d2672b58283592acd72ffc4d47bd3d6ef66bc40c48f0a5526e6cd`.

| Estrutura | Função e restrições |
| --- | --- |
| `atividades_economicas` / `AtividadeEconomica` | Descrição original, `descricao_normalizada` única, `descricao_busca` para pesquisa e indicador `ativo` |
| `atividade_economica_codigos` / `AtividadeEconomicaCodigo` | Relação **1:N** com a atividade; `seguradora`, `codigo` armazenado como string e `ativo`; unicidade de `(seguradora, codigo)` |
| `catalogo_atividade_importacoes` / `CatalogoAtividadeImportacao` | Histórico de origem, hashes, contagens e códigos ausentes que precisam de revisão |
| `leads.atividade_economica_id` | Seleção identificada no catálogo, além da descrição persistida em `descrever_atividade` |

O `TokioCnaeSeeder` valida todo o arquivo antes da escrita: cabeçalho, UTF-8, campos vazios, descrição de até 255 caracteres, códigos de cinco dígitos no catálogo recebido, duplicações, hash e contagens da identificação. Importa em transação com `upsert` em lotes de 100; resolve IDs e mantém os vínculos dos códigos. Repetir a importação não duplica atividades/códigos nem muda seus IDs, mas cria um novo registro de histórico da execução. Não reativa automaticamente registros desativados. Códigos ausentes numa atualização são registrados para revisão, **sem exclusão ou desativação automática**; dados de outras seguradoras são preservados.

Comando existente para carga em ambiente preparado: `php artisan db:seed --class=TokioCnaeSeeder --no-interaction`. Esta revisão não executa migrations nem carga no banco da aplicação; a existência de migrations/Seeder não comprova que todos os ambientes estejam atualizados.

**Decisão de envio encerrada em 27/10/2026:** a seleção do formulário identifica a atividade. O usuário autorizou enviar qualquer um dos códigos Tokio associados à mesma descrição. Na implementação futura, selecionar um código ativo de maneira determinística (critério técnico adotado: menor código numérico ativo), sem preferência comercial atribuída a ele, e congelar a escolha na rodada. Preservar todos os códigos e a relação 1:N. Enviar somente o código escolhido como número em `CNAE`, nunca ID da atividade, objeto, lista ou string com `|`; manter armazenamento textual do catálogo. Ausência de código ativo é inconsistência cadastral, não autorização para inventar outro.

#### Pesquisa e persistência da atividade comercial

Fluxo atual: `commercial-activity.blade.php` → `resources/js/commercial-activity.js` → rota `simulation.activities` (`GET /simulacao/atividades`) → `SearchCommercialActivityRequest` → `CommercialActivityController` → `AtividadeEconomica`.

- O componente integra `rental-type.blade.php` e o formulário compartilhado pelos três perfis. Aparece somente no comercial, junto do ramo; o usuário digita para filtrar e deve selecionar uma opção existente.
- A busca é **somente por descrição**, sem pesquisa de códigos e sem códigos apresentados nas sugestões. Normaliza caixa, acentos e pontuação em `descricao_busca`; cada palavra precisa ocorrer na descrição, independentemente da ordem. Não há correção aproximada de erros ortográficos nem associação inventada de termos desconhecidos.
- A requisição aceita texto de 2 a 100 caracteres; após normalização, termos com menos de dois caracteres não retornam opções. Retorna até dez atividades e `has_more`, buscando onze para detectar resultados adicionais; há limite de 60 requisições/minuto na rota.
- A relação com `atividade_economica_codigos` **continua consultada para elegibilidade**, por `scopeDisponiveis`: atividade ativa com pelo menos um código ativo da Tokio. Remover a busca pelo código não significou remover essa relação nem esse filtro.
- O Request exige `atividade_economica_id` disponível no comercial e exclui o campo no residencial. O Controller lê a descrição do registro validado; não confia no texto livre enviado como descrição oficial.
- `Lead::atividadeEconomica()` expõe a relação. Alterar `descrever_atividade` para outro texto invalida a seleção anterior no mutator; edições posteriores precisam preservar a consistência entre ID e descrição antes de cotar.

**Ramo de atividade:** `business-activity.blade.php`, enum `RamoAtividade` e `leads.ramo_atividade` já existem. Valores: Comércio 21205, Indústria 21204, Serviços 21206. São independentes do CNAE. No residencial, o Request descarta o ramo. No comercial, ainda usa `nullable`: a tolerância autorizada antes da view permaneceu no backend mesmo após o select `required`. Corrigir essa lacuna na implementação seguinte e validar dados de leads antigos antes de chamar a Tokio.

#### Empresa constituída: decisão local e contrato de saída

O campo “Empresa constituída há mais de 2 anos?” aparece somente quando **tipo de locação comercial e documento CNPJ**. Para CPF ou residencial, não deve aparecer nem ter resposta reaproveitada. CNPJ continua exigindo nome e CPF do responsável em campos próprios.

| Resposta / contexto | Comportamento definido e implementado |
| --- | --- |
| Comercial + CNPJ + Sim | Request aceita `S`; calcula a referência de fundação sete anos antes do dia atual em `America/Sao_Paulo`, sem overflow em ano bissexto; persiste tipo de pessoa J, constituição S e data calculada |
| Comercial + CNPJ + Não | Bloqueia avanço/envio, exibe modal e é rejeitado também no servidor; não salva uma solicitação válida nem envia cotação |
| Comercial + CNPJ sem resposta | Request exige confirmação; não assume Sim |
| Fora de comercial + CNPJ | Exclui resposta de constituição e não mantém data calculada para um contexto incompatível |

Mensagem definida: **“Empresa com menos de dois anos não tem aceitação. Por favor fazer análise com CPF.”** O modal contém botão **“Ir para o campo CPF/CNPJ”**, que retorna à etapa de identificação e foca o campo de documento; fechar o modal não torna uma resposta Não válida. Essa é a política de elegibilidade do sistema definida pelo usuário, não uma comprovação documental de que todas as seguradoras têm a mesma restrição.

Persistência: `leads.tipo_pessoa`, `leads.empresa_constituida` e `leads.data_fundacao_calculada`, criados pela migration `2026_10_14_121750_add_company_constitution_to_leads_table.php`. `CompanyConstitution` deriva os valores no servidor; não receber data calculada ou tipo de pessoa como autoridade do navegador. O tipo de pessoa é derivado do documento também fora do comercial: F para CPF e J para CNPJ.

`Lead::tokioCompanyConstitutionParameters()` já expõe `tpPessoa`, `empresaConstituida` e `dataFundacao` formatada como **`DD/MM/AAAA`**. É um helper parcial: no payload final, `tpPessoa` pertence à raiz e os outros dois campos pertencem aos pretendentes. Não espalhar os três indiscriminadamente na raiz, nem enviar `null` fora do cenário aplicável.

A data persistida é **uma referência calculada por regra de negócio**, explicitamente identificada assim no schema, e não a data real de abertura consultada em cadastro oficial. O builder deve usar o valor persistido/snapshot da rodada, não recalcular outra data em cada leitura da página. O uso desse valor como `dataFundacao` e a aceitação de `tpPessoa = J` precisam de confirmação da Tokio; a print recebida ainda restringe `tpPessoa` a F e descreve os dados empresariais como PF não residencial.

#### Pretendentes, estado civil e vínculo: esclarecimento encerrado

Não criar outro campo de formulário para vínculo. O serviço `CompanyApplicantService` sincroniza `leads.tipo_vinculo` e `lead_conjugues.tipo_vinculo` com enum `TipoVinculoEmpresa`: valores internos `titular`/`socio`, descrições `Titular`/`Sócio`. A migration `2026_10_15_120654_add_company_roles_to_leads_and_spouses.php` inclui preenchimento dos vínculos em registros anteriores elegíveis. A sincronização participa do cadastro em `SimulationController` e da edição em `LeadReanalysisService`, limpando vínculos quando o contexto deixa de ser elegível.

| Contexto comercial com CNPJ | Pretendente | `pretendentePrincipal` | `estadoCivil` Tokio | `tipoVinculo` Tokio |
| --- | --- | --- | --- | --- |
| Solteiro | Responsável principal, com seu nome e CPF | `S` | `"18485"` | `21147` — Titular |
| Casado ou união estável | Responsável principal, com seu nome e CPF | `S` | `"18490"` | `21147` — Titular |
| Casado ou união estável | Cônjuge, com seu próprio nome e CPF | `N` | `"18490"` | `21149` — Sócio |

O esclarecimento do usuário está encerrado: **não enviar CPF cruzado e não procurar um atributo de CPF do cônjuge inexistente**. A lista `pretendentes` contém uma entrada para cada pessoa, identificada por S/N. Estado civil é código simples. `CompanyApplicantService` exige nomes e CPFs válidos e distintos; responsável e cônjuge não podem ser a mesma pessoa.

O `TokioCompanyApplicantsPayloadBuilder::build()` já recebe dados compartilhados no contrato Tokio, acrescenta o CNPJ da empresa e sobrescreve nome, CPF, principal, estado civil, `vinculoEmpresa = S` e tipo de vínculo em cada objeto. Assim, dados comuns da empresa podem ser repetidos no secundário, mas a identidade e o indicador de principal são sempre próprios. Não duplicar inadvertidamente informações pessoais do responsável, como nascimento, apenas por fornecê-las no array compartilhado.

Para outras APIs, `CompanyApplicantService::descriptions()` oferece descrições, preservando União estável internamente. Os códigos numéricos Tokio não devem ser propagados para campos descritivos de outras companhias; cada adapter ainda precisa aplicar seu contrato. A existência desse serviço não significa que os providers existentes já o consumam.

**Limites atuais:** esse builder aceita apenas comercial com CNPJ e estado civil solteiro/casado/união estável. Divorciado, separado, viúvo, estado civil ausente, comercial com CPF e residencial não têm montagem resolvida por ele. Não usar o builder empresarial como builder universal de cotação. Embora os códigos documentais de outros estados estejam em T5, a regra de vínculo desses cenários ainda precisa ser definida para ampliar o envio.

#### Nascimento e demais padrões consolidados

`data_nascimento` é obrigatório no formulário compartilhado; para CNPJ, é o nascimento do responsável. A regra final definida pelo usuário é **mínimo de 18 e máximo de 120 anos completos**, inclusive: aceita no aniversário de 18 e durante os 120, bloqueando a partir do aniversário de 121. Essa decisão substitui a premissa intermediária de não exigir idade mínima.

`App\Support\BirthDate`, `StoreSimulationLeadRequest` e `StorePublicLeadRequest` compartilham normalização e validação: apenas remoção de espaços externos, string no formato interno `Y-m-d`, data existente e limites calculados em São Paulo. Não corrigir automaticamente dia, mês ou ano inválido. Há mensagens para ausência, formato inválido, futuro e idade fora dos limites; os três formulários têm `min`/`max` coerentes com o servidor. Essa implementação está na árvore de trabalho e teve 36 testes direcionados aprovados na etapa anterior; não é evidência de validação remota Tokio. O contrato Tokio recebido não apresenta nascimento: não inventar um atributo para enviá-lo.

Permanecem as decisões já registradas: Casa 10001 para residencial, Sala comercial 21103 para comercial; Seguro Novo 26470 no PF residencial, Troca de local de sede 21114 no PF não residencial; Sem assistência 26203; `multiploLMI = 30`; `capitalInicial = 450000` somente no comercial. Esses padrões de payload não estão todos conectados a um builder completo. A data de fundação de sete anos é uma referência cadastral; LMI mantém sua semântica de cobertura. Para pagamento, a regra confirmada é 30 meses = 30 parcelas mensais, uma por mês.

#### O que falta conectar para iniciar a Tokio

1. **Cenários e payload completo:** aplicar T15: PF residencial/comercial na API, PJ manual, um código ativo por atividade e PF comercial com empresa não constituída derivada internamente. Corrigir ramo ausente e completar os cenários não cobertos pelo builder parcial. Compor raiz, item, locação, pretendentes e coberturas nos níveis corretos; o builder empresarial CNPJ não deve habilitar chamadas PJ.
2. **Acesso remoto:** implementar configuração, autenticação, cliente HTTP e provider no padrão existente. `InsuranceProviderResolver` ainda resolve somente Too e Pottencial; não há serviço HTTP Tokio conectado. Confirmar contrato OAuth e respostas reais sem registrar segredos.
3. **Retorno e estados:** aplicar T20: CAL/aprovação e qualquer outro código/recusa, sem encaminhamento ou polling. Mensagens explicativas não determinam o status. Preservar Sem assistência e não fixar N = Básica: o retorno diagnóstico real traz N/Plano Completo.
4. **Pagamento e comparação:** manter limite de 30 e uso de 30 parcelas, fatura com imobiliária e crédito sem imobiliária; destacar `vlPremPar` da opção com `qtd = 30`, já incluindo encargos. O agrupamento `prazo` era a leitura documental inicial; T19 mostra parcelamento dentro de `vista`, exigindo conferir a representação no payload final sem mudar a intenção de pagamento. As opções até 12x do fixture não mudam o padrão 30x nem autorizam extrapolar preços. A refatoração coordenada da normalização e comparação compartilhada continua necessária.
5. **Rodadas e reanálise:** aplicar a decisão de 28/10 de criar nova cotação/solicitação de análise para todas as companhias, mantendo histórico e proteção contra resposta obsoleta. Na Tokio, POST `/cotar` sem os headers da cotação anterior. Não usar endpoints de reanálise ou recálculo; preservar etapas obrigatórias de criação. A orquestração será adaptada somente na etapa futura autorizada.
6. **Documentos e conclusão:** obter contrato dos impressos/pareceres necessários e manter resultado, comparação e entrega independentes de falhas técnicas. Não inferir rota de consulta, PDF, polling ou contratação a partir de nomes de menus.

#### Verificação desta revisão documental

Foram confrontados as instruções e prints da conversa, o estudo anterior, o histórico Git, migrations, modelos, Requests, Controllers, serviços, builders, componentes de formulário e testes existentes. Os arquivos originais citados continuam disponíveis em Downloads; os hashes do PDF CNAE e do CSV foram conferidos com a identificação versionada. Não foi necessária nova transcrição do vídeo para registrar os esclarecimentos posteriores. Executado `php artisan test --compact tests/Unit/TokioCnaeCatalogTest.php`: **4 testes aprovados, 7.651 assertions**, sem carga no banco de trabalho; `git diff --check` também passou.

A revisão não executa chamada à seguradora, não altera código de aplicação nem aplica migrations/Seeder no banco de trabalho. As mudanças de nascimento já presentes foram preservadas. Testes locais e catálogo íntegro não substituem exemplos reais e confirmação dos pontos de contrato pendentes em T12.

### T15. PF na API, PJ manual e atividade comercial — 27/10/2026

Esta seção substitui as decisões anteriores incompatíveis, especialmente o envio de `tpPessoa = J`, a tentativa de homologar PJ na API e a pendência de escolher um código entre os associados à mesma descrição. Fontes: esclarecimento do usuário, duas prints do Portal e resposta do suporte em `C:\Users\Usuario\Downloads\A API atualmente suporta apenas os.txt`, lida nesta revisão. Não houve chamada remota nem alteração de código de aplicação.

#### Elegibilidade e atendimento manual

O suporte confirma expressamente: a API suporta somente Pessoa Física, tanto Residencial quanto Não Residencial. Pessoa Jurídica está disponível exclusivamente via Portal. Na API, `tpPessoa` deve ser sempre `F`; `icResidencial` será `S` no residencial e `N` no não residencial.

| Documento/contexto do lead | Tratamento Tokio no sistema |
| --- | --- |
| CPF + residencial | Cotação automática PF: `tpPessoa = F`, `icResidencial = S` |
| CPF + comercial | Cotação automática PF: `tpPessoa = F`, `icResidencial = N`, com dados comerciais aplicáveis |
| CNPJ + residencial ou comercial | Não iniciar análise remota Tokio; disponibilizar orientação para cotação manual |

Textos determinados pelo usuário para o card Tokio de PJ:

- Visualização por imobiliária: **“entrar em contato com corretor”**.
- Visualização por corretor: **“realizar cotação manualmente”**.

São mensagens por perfil de quem visualiza, não valores retornados pela API. O texto para eventual visualização por locatário/proprietário não foi especificado nesta decisão; não criar novo acesso à página por causa dela.

Consequências para a implementação futura: registrar explicitamente a indisponibilidade de análise automática para PJ, sem simular aprovação, recusa ou erro técnico e sem preço zero. Esse estado não pode deixar o pacote aguardando indefinidamente nem entrar no ranking ou contar como recusa para declarar recusa unânime. As demais companhias continuam seu fluxo elegível. Não gerar carta de recusa Tokio a partir dessa indisponibilidade. Não chamar cotação Tokio com o CPF do responsável para disfarçar a origem PJ.

As estruturas já persistidas de tipo de pessoa, constituição, data calculada e vínculos permanecem no projeto. A decisão não autoriza remover campos ou modificar a política dos demais providers. O builder parcial anteriormente preparado para comercial com CNPJ passa a ser referência histórica/local, não autorização de envio à Tokio. A data calculada de sete anos não será enviada no novo fluxo automático PF comercial definido abaixo.

#### Comercial com CPF e empresa não constituída

O usuário definiu que esse fluxo atende empreendedores em Pessoa Física. Não haverá CNPJ nem exibição do campo `company-constitution.blade.php` nesse cenário. A interpretação para a montagem interna é `empresaConstituida = N`: a API exige o indicador, mas o solicitante não precisa responder a um novo campo. Não confundir ocultar o campo no formulário com omitir o indicador exigido no payload.

Além dos dados comuns da cotação e dos pretendentes, preencher os campos comerciais obrigatórios **`CNAE`, `ramoAtividade`, `vinculoEmpresa` e `tipoVinculo`**. Omitir `cnpj` e `dataFundacao`, sem valores vazios ou uma data fictícia substituta. A afirmação sobre esses quatro campos delimita os dados empresariais condicionais; não elimina os demais campos comuns obrigatórios da cotação nem revoga automaticamente padrões já definidos, como `capitalInicial` comercial.

A regra se aplica também ao caminho de análise com CPF indicado pelo modal de empresa com menos de dois anos. O sistema não deve bloquear o cenário PF comercial só por derivar empresa não constituída. Não alterar nesta etapa o bloqueio já definido para submissão comercial com CNPJ e resposta Não, nem equiparar juridicamente “menos de dois anos” a “empresa não constituída”: trata-se do caminho operacional PF determinado pelo usuário.

As prints mostram que no Portal a seleção Não exibe CNAE e Ramo de Atividade sem CNPJ/data de fundação, enquanto Sim acrescenta CNPJ e Data de Fundação. Também mostram mensagens de obrigatoriedade de CNAE, Código CNAE e Ramo de Atividade. Essas mensagens não demonstram dois atributos distintos de CNAE no JSON: o contrato estudado continua usando `CNAE` numérico. A obrigatoriedade de `vinculoEmpresa` e `tipoVinculo` vem do contrato e da explicação do usuário, não de campos visíveis nessas duas imagens. A existência de campos CNPJ no Portal não comprova suporte a `tpPessoa = J` na API.

**Casal comercial PF definido em 28/10:** ambos Titulares (21147), com participação 50 para cada pessoa, conforme a regra geral de cônjuges. Fazer as individuais em paralelo e a conjunta somente se ambas forem recusadas. A regra anterior Titular/Sócio não é o padrão desse cenário. Em esclarecimento posterior, o usuário definiu Titular com participação 100 no cenário sem cônjuge, `respondeFinanceiramente = S` para ambos no casal e renda de 4 × aluguel para cada pessoa, inclusive na conjunta, sem divisão.

#### Um código por atividade

O usuário autorizou escolher um dos códigos quando vários correspondem à mesma descrição. A pendência de negócio está encerrada. Critério técnico para a futura implementação: escolher o menor código numérico ativo da Tokio vinculado à atividade selecionada, de maneira estável e reproduzível, sem atribuir vantagem de cobertura ou preço a esse código. Persistir a escolha no snapshot da rodada para que consultas e retries usem o mesmo valor.

Enviar um único número em `pretendentes[].CNAE`. Manter descrições únicas, todos os códigos, metadados de importação, relação 1:N e busca somente por descrição. Não enviar lista, objeto, separador `|` nem ID local. Não escolher código inativo ou de outra seguradora. Se não houver código elegível, tratar a inconsistência antes de cotar.

#### Pendências após este esclarecimento

Encerradas: suporte PF comercial (`icResidencial = N`), restrição de PJ à cotação manual, escolha de um código para descrições com múltiplos códigos e ausência de CNPJ/data de fundação/campo de constituição no fluxo PF comercial, com indicador N derivado internamente.

Atualização de 28/10: vínculo e participação do casal comercial PF definidos como dois Titulares com 50% cada; responsabilidade financeira definida como `respondeFinanceiramente = S` para ambos; fluxo de individuais/conjunta definido na seção geral. Participação sem cônjuge (Titular, 100), renda individual de 4 × aluguel para ambos e pinturaNova S nos cenários com pintura foram posteriormente definidos. Continuam relevantes apenas as validações técnicas: mapeamento/validação das coberturas por origem conforme regra geral de 28/10; validação das datas conforme regra geral de vigência de 30 meses já definida; catálogo de situação e conclusão da análise; identificação do retorno Sem assistência; validação da associação de parceiros Tokio para fatura, cuja fonte e alternativa por cartão estão definidas em T21. Comissão de 15%, valor unitário da parcela já com encargos, parcelas iguais e base de pagamento permanecem definidos pelas regras gerais. Documentos e detalhes de transporte serão validados no desenvolvimento; isso não comprova seu funcionamento antecipadamente.

### T16. Aprovação automática no Aceite W e mensagens — 27/10/2026

**Nota de precedência de 28/10:** as hipóteses históricas abaixo sobre encaminhamento e dependência de mensagens foram substituídas por T20. Os CPFs homologados e os exemplos permanecem úteis; CAL é o critério de aprovação e os demais códigos indicam recusa, sem encaminhamento via API.

Fontes: orientação de homologação reproduzida pelo usuário e print do Postman enviada nesta data. Esta revisão analisa uma execução apresentada pelo usuário; não executa chamadas à Tokio nem transforma o exemplo em garantia de aprovação em produção.

#### Condições de teste informadas

- Ambiente: **Aceite W**.
- Operação: **POST `https://apim-act.tokiomarine.com.br/massificados/cotador-fianca-service-w/api/cotar`**.
- Aluguel: **R$ 400,00**, enviado em `coberturas.vlAluguel` como número JSON `400.00`.
- Usar um dos CPFs previamente homologados abaixo para validar aprovação automática. Conforme orientação recebida, CPFs aleatórios no aceite resultam em encaminhamento para análise ou recusa; não devem ser usados como caso de teste que exige aprovação.

| CPF de teste informado | Valor textual sem máscara para `pretendentes[].cpf` |
| --- | --- |
| 092.380.376-98 | `09238037698` |
| 383.295.608-56 | `38329560856` |
| 385.669.788-89 | `38566978889` |

São identificadores de teste fornecidos para esse ambiente, não CPFs a aplicar em leads reais ou produção. Preservar zeros iniciais e enviar como string. Não alterar o padrão de aluguel do sistema para R$ 400,00: esse valor é condição do cenário de homologação. Não extrapolar essa autorização para Aceite Y ou outros ambientes. A orientação lista três CPFs; a imagem demonstra a chamada com o primeiro, não três execuções distintas.

#### Evidência visível na imagem

A chamada retorna **HTTP 200 OK**. O objeto `cotacaoInfo` contém identificação, datas, token da cotação e `cdSitucCotac = "CAL"`; `abaInicial.descricao` é `Cálculo` e sua rota é `calculo`. Os identificadores, tokens e credenciais visíveis não são necessários ao estudo e não foram transcritos.

Trecho relevante transcrito, deliberadamente parcial:

```json
{
  "cotacaoInfo": {
    "cdSitucCotac": "CAL"
  },
  "mensagens": {
    "success": [
      "Dados do imóvel salvos com sucesso.",
      "Cadastro aprovado."
    ]
  }
}
```

`mensagens` é o objeto; **`success` é uma lista de mensagens**, não um booleano nem um objeto único de aprovação. O nome do campo não basta para classificar o resultado, pois a própria resposta contém uma mensagem de salvamento e outra de aprovação. A informação de aprovação está no conteúdo explícito **`Cadastro aprovado.`**, conforme esclarecido pelo usuário.

O caso prova que uma chamada de cotação pode retornar aprovação automática nesse ambiente. Não comprova que todas as cotações terminem imediatamente: o suporte também informa encaminhamento para análise. `CAL` fica registrado como código observado em um caso aprovado, sem inferir que sempre significa aprovação ou inventar sua expansão. HTTP 200 continua indicando sucesso HTTP, não uma decisão cadastral por si só.

#### Limites do cenário e aplicação futura

O body visível usa `assistenciaDesejada = "26206"` (Completa), `pinturaNova = "S"`, um pretendente principal e vigência do seguro de 27/10/2026 a 27/10/2027. Esses dados descrevem o exemplo; não substituem os padrões aprovados do projeto, especialmente Sem assistência `26203` e a base de pagamento de 30 meses. Não copiar nome, datas ou credenciais do exemplo para dados reais.

As linhas visíveis de condomínio, IPTU, água, luz e gás estão comentadas. Não tratá-las como valores comprovadamente recebidos pela seguradora; payloads gerados pelo sistema devem ser JSON válido. A print não mostra o corpo completo, os preços, a quantidade de parcelas ou a alternativa Sem assistência. Portanto, a evidência de aprovação não encerra a validação de pagamento, de Sem assistência ou de todos os cenários comerciais.

Na futura normalização, reconhecer a mensagem explícita documentada de aprovação, preservando mensagens e estado bruto. Não usar busca genérica por `sucesso`, mera existência de `success`, preço preenchido ou `CAL` isolado para aprovar. Respostas sem uma decisão reconhecida, ou com sinais conflitantes, devem permanecer sem classificação conclusiva até validação do contrato, sem conversão automática em aprovação ou recusa.

Verificações futuras direcionadas: aprovação com a mensagem documentada; success contendo somente confirmação de salvamento; mensagens ausentes ou formato inesperado; encaminhamento e recusa conforme exemplos ainda a receber. Não realizar POSTs repetidos para tentar transformar um CPF não homologado em aprovado.

**Pendências encerradas:** disponibilidade de dados homologados para testar aprovação no W; aluguel exigido nesse cenário; localização e formato observado da mensagem explícita de aprovação.

**Pendências que permanecem:** mensagens/códigos exatos de recusa e encaminhamento à análise, consulta ou mecanismo de conclusão quando encaminhada e tratamento de eventual conflito entre situação e mensagens. A ausência de um catálogo completo de `cdSitucCotac` não apaga a evidência recebida de aprovação; limita a classificação dos demais retornos. Permanecem também as decisões de negócio não tratadas neste envio, registradas em T15.

### T17. Fragmentos comerciais: empresa constituída e não constituída — 27/10/2026

Fontes lidas integralmente: `C:\Users\Usuario\Downloads\cenário-com-Empresa-Não-constitida.txt` e `C:\Users\Usuario\Downloads\cenario-com-Empresa-constituida.txt`. São exemplos de entrada fornecidos pelo usuário, não respostas de execução nem instruções para alterar as regras do projeto automaticamente. Esta revisão documenta a estrutura e suas consequências; não implementa o payload nem executa chamadas remotas.

#### Formato e comparação estrutural

Ambos os arquivos são **fragmentos** com a propriedade `pretendentes`, sem as chaves do objeto raiz e com vírgula final. Não são documentos JSON completos executáveis isoladamente. A validação local foi feita envolvendo cada fragmento em um objeto raiz e retirando somente a vírgula final: ambos passam no parser e contêm exatamente dois elementos na lista. Os arquivos originais foram preservados.

| Posição / campo | Empresa não constituída | Empresa constituída |
| --- | --- | --- |
| `pretendentes[0].empresaConstituida` | `"N"` | `"S"` |
| `pretendentes[0].CNAE` | `24465` (número) | `24465` (número) |
| `pretendentes[0].ramoAtividade` | `21205` (número) | `21205` (número) |
| `pretendentes[0].cnpj` | Ausente | String alfanumérica de exemplo |
| `pretendentes[0].dataFundacao` | Ausente | `"26/02/2026"` |
| `pretendentes[1].cpf` | String de 11 dígitos | A mesma string de 11 dígitos |
| `pretendentes[1].pretendentePrincipal` | `"S"` | `"S"` |
| `pretendentes[1].vinculoEmpresa` | `"S"` | `"S"` |
| `pretendentes[1].tipoVinculo` | `"21149"` (string) | `"21149"` (string) |
| `pretendentes[1].percentualParticipacao` | `100` (número) | `100` (número) |

A diferença entre os dois fragmentos limita-se ao indicador S/N e à presença de `cnpj` e `dataFundacao` no primeiro objeto. O objeto da pessoa principal é idêntico. O CPF desses exemplos não está entre os três CPFs homologados informados em T16; não tratá-lo como dado que garante aprovação automática. Não é necessário reproduzir a identidade pessoal no estudo para preservar a estrutura.

#### Empresa e pessoa são objetos diferentes

O primeiro objeto representa os dados empresariais da atividade. Não contém `cpf`, `nome`, `pretendentePrincipal` nem vínculo. O segundo representa a pessoa física principal, com CPF, indicador de principal e vínculo, sem repetir CNAE, ramo ou constituição. Assim, **dois elementos em `pretendentes` não significam duas pessoas ou um casal** nesses exemplos.

Essa estrutura corrige a hipótese de espalhar os dados da empresa por todos os pretendentes. Na montagem futura do cenário comercial, preservar a separação mostrada e a ordem dos exemplos; não aplicar validação de CPF/nome ao objeto empresarial. Não acrescentar CPF artificial ou `pretendentePrincipal` ao primeiro elemento para satisfazer um modelo local universal. A ordem está observada nos exemplos, sem prova adicional de que seja uma exigência formal da API.

Não há campo `nome` nesses fragmentos, embora a tabela anterior o descreva para pessoas. Isso deve ser conferido ao completar a requisição: um trecho parcial não comprova que nome passou a ser proibido ou dispensável. Tampouco os arquivos incluem `tpPessoa`, `icResidencial`, item, locação ou coberturas, que continuam sujeitos ao contrato e às regras gerais.

#### Vínculo e participação observados

Nos dois exemplos, a pessoa principal recebe `vinculoEmpresa = "S"`, `tipoVinculo = "21149"` (**Sócio**) e `percentualParticipacao = 100`. Ser principal (`pretendentePrincipal = "S"`) não implica necessariamente vínculo Titular (`21147`): são campos com significados distintos, como estes exemplos demonstram.

`percentualParticipacao` é uma informação nova no material Tokio estudado. O exemplo usa 100, não 1 nem 0.15. Não confundir com `pcComissao`, com a comissão geral de 15% ou com `participationPercentage = 1` da Pottencial. Não inferir obrigatoriedade universal, soma exigida ou distribuição de participação entre pessoas sem documentação adicional.

A tipagem também merece preservação: os códigos `CNAE` e `ramoAtividade` são números JSON, enquanto `tipoVinculo` vem entre aspas nos dois arquivos, em contraste com a classificação numérica da tabela anterior. Registrar essa evidência para o adapter e verificar o contrato efetivo na integração, sem alterar o armazenamento interno do catálogo.

**Decisão posterior de 28/10:** para o casal comercial PF, ambos serão Titulares (21147), com participação 50 cada. O exemplo Sócio/100 permanece como evidência do fragmento recebido, não como padrão do casal. A composição de pessoas segue as duas individuais paralelas e a conjunta condicional da regra geral. Não copiar 100 para cada pessoa nem alterar automaticamente 50 para 100 ao separar as análises.

#### Compatibilidade com as regras vigentes

- **PF comercial do projeto:** o cenário Não corresponde à decisão de T15: empresa não constituída derivada internamente, sem novo campo no formulário, sem CNPJ/data de fundação. Os dados de empresa e os de vínculo pessoal agora têm posições distintas comprovadas nos exemplos.
- **Cenário Sim:** manter documentado para referência contratual. Sua existência não revoga a decisão de não chamar Tokio automaticamente para leads PJ. Uma empresa vinculada dentro de uma requisição não é, por si só, evidência de `tpPessoa = J`; o suporte continua determinando `tpPessoa = F` na API.
- **CNPJ alfanumérico:** o exemplo contém letras e números, não apenas uma máscara com pontuação. Não remover letras silenciosamente se esse cenário vier a ser utilizado. O exemplo não comprova validação desse identificador nem autoriza modificar a validação atual dos formulários nesta etapa.
- **Data de fundação:** o exemplo de Sim usa 26/02/2026, menos de dois anos antes desta revisão. É um valor de entrada sem resposta associada; não comprova aprovação para empresa com menos de dois anos nem revoga a política local de elegibilidade. Também não é a referência calculada de sete anos definida anteriormente.
- **Catálogo:** conferência local encontrou `24465` associado a **Comércio Varejista de Calçados** no CSV versionado; `21205` corresponde a **Comércio**. São valores coerentes entre si no exemplo, mas não padrões para todos os leads. Continuar usando a atividade e o ramo selecionados, com um único código ativo por descrição conforme T15.
- **Demais campos:** a ausência de capital inicial, estado civil, renda e outros atributos neste trecho não revoga decisões anteriores nem prova que esses campos sejam inválidos. Completar o payload conforme cenário e contrato.

#### Verificação e consequências para o desenvolvimento

Verificado programaticamente: fragmentos inválidos isoladamente e válidos após composição mínima; dois objetos em cada lista; nomes dos campos empresariais e pessoais; `tipoVinculo` string e participação numérica. Conferido o código 24465 no catálogo local. Nenhuma autenticação, cotação, alteração de formulário, migration ou teste remoto foi realizado.

O futuro builder PF comercial deve suportar uma lista com objetos de papéis diferentes. O builder parcial de CNPJ que replicava dados compartilhados nas pessoas não deve ser reutilizado sem adaptação. A implementação deverá testar a separação empresa/pessoa e a ausência de CNPJ/dataFundacao no cenário Não, além dos casos de vínculo que forem confirmados. As pendências de retorno de recusa/encaminhamento e acompanhamento da análise permanecem independentes desses exemplos de entrada.

### T18. Comandos de diagnóstico OAuth e cotação no Aceite W — 27/10/2026

Implementação isolada para inspeção manual, sem habilitar provider Tokio, alterar leads ou disparar Jobs de análise:

```bash
php artisan tokio:token
php artisan tokio:token --show
php artisan tokio:test-quote --cenario=aprovada
php artisan tokio:test-quote --cenario=aprovada --cpf-aprovado=2
php artisan tokio:test-quote --cenario=recusada
php artisan tokio:test-quote --cenario=encaminhada
```

**Histórico do comando — orientação superada em T20:** `--cenario=encaminhada` foi adicionado para investigar a hipótese anterior usando CPF aleatório. O suporte posteriormente confirmou que esse resultado não existe via API. A opção continua presente no código neste momento, mas apenas envia o mesmo tipo de CPF do cenário recusada; não deve ser apresentada como cenário de negócio disponível. Sua remoção/ajuste fica para alteração de código posterior. Esta revisão é documental e não modifica o comando.

O token é obtido por `TokioAuthentication` com formulário OAuth e credenciais configuradas; não é persistido. `--show` exibe o token somente fora de produção. A autenticação real foi validada na etapa anterior com HTTP 200; isso não comprova o resultado de cotação.

`TestTokioQuoteCommand` chama `TokioQuoteDiagnostic`: valida cenário/configuração, gera token novo e envia POST exclusivamente ao Aceite W. O cenário aprovada seleciona um dos três CPFs homologados por índice 1–3, preservando zeros à esquerda. Recusada gera um CPF com dígitos verificadores válidos fora dessa lista; pode retornar encaminhamento, e não recebe classificação forçada de recusa. Os dois usam aluguel 400 para variar somente o CPF no experimento.

O payload diagnóstico reproduz os campos do exemplo PF residencial: Apartamento 10000, motivo 10008, assistência Completa 26206, pintura interna/externa, comissão 15 e demais percentuais zero; usa nome de teste e endereço do exemplo. Datas são atualizadas para hoje em São Paulo e um ano depois, sem overflow. Esse fixture de homologação não altera os padrões da futura integração (Casa, Seguro Novo, Sem assistência e base de pagamento de 30 meses). Não representa um builder final para leads reais ou comercial.

Saída JSON: cenário, payload efetivamente enviado, resumo da autenticação, origem das credenciais por tentativa, código HTTP, descrição e corpo retornado. Respostas não JSON também são preservadas como texto. `success` do relatório indica HTTP 2xx, não aprovação de crédito. Objetos/listas do retorno são preservados; tokens e credenciais são ocultados. Não há gravação automática dos retornos nem geração de documentos.

**Configuração do operador e fallback autorizado pelo usuário:** `TOKIO_CPF_USUÁRIO` (com acento) é a chave principal; aceita `TOKIO_CPF_USUARIO` como alternativa. Corretor e senha usam `TOKIO_CODIGO_CORRETOR`/`TOKIO_SENHA_OPERADORA`, compatíveis com `COD_CORRETOR`/`SENHA_OP`. Os dados do suporte ficam somente no `.env` local em `TOKIO_SUPORTE_CODIGO_CORRETOR`, `TOKIO_SUPORTE_CPF_USUARIO` e `TOKIO_SUPORTE_SENHA_OPERADORA`; exemplos versionados não contêm seus valores. O valor existente de `TOKIO_CPF_USUÁRIO` foi preservado.

Se o conjunto principal estiver incompleto/inválido, usar o conjunto completo do suporte antes da primeira cotação. Se o POST retornar 401/403 e houver um conjunto alternativo distinto, realizar uma única nova tentativa com os mesmos dados de cotação e headers do suporte. Não misturar partes dos dois conjuntos. Não repetir após timeout, HTTP 400/422/429/5xx, redirecionamento, recusa de negócio ou encaminhamento. Sem fallback entre W, Y e produção. A execução do diagnóstico é bloqueada no ambiente de aplicação production.

O cURL do suporte fornece headers de cotação e um Bearer antigo; não fornece outro conjunto de client_id/client_secret/scope. Por isso o fallback não substitui credenciais OAuth e jamais reutiliza aquele Bearer: se a emissão do token falhar, a cotação não é enviada. Essa limitação é explícita no relatório.

Verificação local: testes Pest de payloads, CPFs, autenticação, respostas, proteção de segredos, fallback limitado e falhas de transporte; Pint e diff conferidos. Nesta etapa do comando de cotação, não foi executado POST real para criar uma cotação.

### T19. Retornos reais de aprovação e recusa: classificação e pagamentos — 27/10/2026

**Precedência e escopo:** esta revisão confronta os dois retornos enviados pelo usuário com as regras já aprovadas. São evidências de resposta do comando diagnóstico, cujo payload não contém todas as regras e os campos da integração final. O usuário reafirmou expressamente: **o limite é 30 parcelas e o sistema utilizará 30 parcelas**. O retorno até 12x deste fixture de um ano não é um limite geral da Tokio, não modifica a base de 30 meses/parcelas e não cria uma nova pendência de negócio sobre a quantidade. Não substituir regras finais por datas, assistências, coberturas ou sugestões desse experimento.

#### Fontes e integridade

| Arquivo recebido em Downloads | Bytes | SHA-256 |
| --- | --- | --- |
| `retorno-cotacao-aprovada.txt` | 33198 | `59d703464ce9209c74c4d727e92aff9b74ffde8eabe23c8c6af3715458c093b2` |
| `retorno-cotacao-recusada.txt` | 1529 | `475824be71b19fe19a61279638b30147844e861f448a14239b13d6035b806c8f` |

Os arquivos são recortes que começam em `"cotacaoInfo":` sem a abertura da raiz e terminam com um fechamento externo excedente. Para análise programática, acrescentou-se somente `{` no início e removeu-se um `}` final, sem modificar campos/valores. Ambos foram então interpretados integralmente. Os originais foram preservados, sem copiar para o repositório identidades, identificadores de negócio ou credenciais. O token já estava `[OCULTO]` nos anexos.

Esses recortes não incluem `http_status`, `authentication`, `fallback_used` ou o payload enviado. Por isso não permitem afirmar o código HTTP destas duas execuções, se o fallback foi usado ou que o request final já foi homologado. A origem no comando e o objetivo de cada teste foram informados pelo usuário; o resultado de negócio é sustentado pelo corpo recebido, não apenas pelo nome do arquivo.

#### Comparação dos resultados

| Informação | Aprovado | Recusado |
| --- | --- | --- |
| `cotacaoInfo.cdSitucCotac` | `CAL` | `REC` |
| `abaInicial` | id 4, Cálculo, rota `calculo` | id 2, Ficha Cadastral, rota `fichaCadastral` |
| `dtPrmroCallo` | `27/10/2026` | `27/10/2026` |
| `dtVldad` | `11/12/2026` | String vazia |
| `success` | Salvamento do imóvel e `Cadastro aprovado.` | Salvamento do imóvel e confirmação de envio de e-mail de recusa |
| `warning` | 6 mensagens | 3 mensagens |
| `danger` | Categoria ausente no recorte | 2 mensagens explícitas de reprovação |
| `calculos` | Um objeto `N` | Objeto vazio `{}` |
| `ordemApresentacaoCalculos` | Lista `["N"]` | Lista vazia `[]` |
| Percentuais da raiz | Comissão 15, pró-labore 0, desconto 0, agravo 0 | Ausentes no recorte |
| `biometrias` | Objeto com CPF como chave e booleano true | Ausente no recorte |

Ambas as respostas possuem identificadores de cotação e negócio reservado: **ter número de cotação não implica aprovação**. A validade vazia da recusa deve permanecer ausência de validade; não convertê-la para hoje nem para uma data padrão. `abaInicial` é metadado do portal, não rota local nem contrato de consulta de status.

#### Filtragem de mensagens e decisão cadastral

Mensagens exatas observadas:

- Aprovação: **`Cadastro aprovado.`** em `mensagens.success[]`.
- Recusa: **`Cadastro Reprovado não passível de reanálise.`** e **`Análise cadastral não aprovada.`** em `mensagens.danger[]`.
- Sucesso operacional sem decisão positiva: **`Dados do imóvel salvos com sucesso.`**, presente nos dois retornos.
- Sucesso operacional da recusa: **`Email de recusa enviado com sucesso. - <referência>`**, presente no recusado; o identificador variável não precisa ser exposto ao cliente.

A hipótese de classificar somente pela existência de success/danger é insuficiente: **success e danger coexistem no recusado**. O campo `success` é uma categoria de mensagens de operações bem-sucedidas, não um booleano de aprovação. Danger também pode vir a conter erros de validação; não converter qualquer mensagem dessa categoria em recusa cadastral sem reconhecê-la.

Critério proposto para o adapter futuro, sem implementação nesta entrega:

| Evidência | Interpretação |
| --- | --- |
| Código válido `CAL`, com ou sem mensagem específica | Aprovação cadastral, conforme T20; preço e condições posteriores são tratados separadamente |
| Qualquer outro código válido, inclusive `REC` | Recusa cadastral, conforme T20; success de salvamento/e-mail não modifica a decisão |
| Somente success de salvamento ou e-mail, sem código válido | Resposta insuficiente; não inferir aprovação ou recusa por texto |
| Somente cálculos/preços, sem código válido | Não inventar decisão a partir da existência de valores |
| Erro HTTP/validação sem decisão cadastral reconhecida | Falha técnica ou de dados, conforme o contrato; não recusa automática |
| Texto de mensagem divergente de um código válido | Preservar a divergência para diagnóstico; classificar pelo código conforme T20 |
| Status ausente, null, vazio ou de tipo inválido | Falha de contrato/dados insuficientes; não é encaminhamento nem código de recusa |

**Substituído pela confirmação T20:** filtrar mensagens para explicação/exibição, preservando seu conteúdo, não para determinar aprovação/recusa. O critério é o código: CAL aprova e qualquer outro código recusa. Não exigir uma frase reconhecida, nem manter outro código como pendente por faltar em uma lista local. Status ausente ou malformado continua sendo problema técnico, não código de negócio.

#### Avisos com consequências para o fluxo

1. **Vigência máxima de cinco anos (60 meses):** aviso presente nos dois retornos. Esse limite refere-se ao contrato de locação e não substitui a regra de pagamento em 30 parcelas. O período final escolhido deve continuar respeitando essa condição contratual.
2. **Mais de um responsável financeiro:** o aviso exige inclusão como novo pretendente. Reforça a separação entre pessoas efetivamente responsáveis. O aviso sozinho não define renda, vínculo ou participação. A decisão posterior do usuário encerrou a dúvida sobre responsabilidade do casal: `respondeFinanceiramente = S` para ambos; vínculo Titular e participação 50 cada no comercial PF, conforme seção geral.
3. **Validação OnLine indisponível:** aparece no recusado, junto de REC e das duas mensagens de reprovação. Um warning de indisponibilidade não deve apagar a recusa explícita recebida; tampouco autoriza repetir automaticamente o POST. Um retorno contendo apenas esse aviso precisa de tratamento próprio, ainda não demonstrado.
4. **Não passível de reanálise:** a recusa contém essa restrição explícita. Pela decisão posterior de 28/10, a ação do sistema será uma nova solicitação pelos endpoints de criação, sem tentar reanalisar ou recalcular o registro recusado. Não criar automaticamente cotações após uma recusa para procurar aprovação; preservar a mensagem anterior e qualquer restrição retornada na nova solicitação. A escolha entre criação e reanálise dedicada não é mais uma pendência.
5. **Envio de e-mail pela companhia:** a resposta afirma que um e-mail de recusa foi enviado. Há, portanto, indicação de efeito externo do POST mesmo sem Jobs locais de notificação. O texto não comprova entrega ao destinatário nem informa qual é o destinatário. Considerar esse efeito em testes e retries; não declarar que criar a cotação não pode disparar comunicação da seguradora.
6. **Aprovação cadastral não equivale a emissão/aceitação final:** o retorno aprovado informa validade do cálculo, condiciona confirmação das condições à biometria facial e não recomenda entrega de chaves antes dessa etapa. O sistema pode apresentar o resultado cadastral e a cotação no escopo atual, preservando as condições; não apresentar apólice emitida ou cobertura garantida. A revisão não autoriza implementar emissão ou biometria.
7. **Datas distintas:** há prazo de transmissão até 27/10/2026 e validade do cálculo até 11/12/2026. O aviso também condiciona garantia dos valores à transmissão até o início da vigência. Não usar `dtVldad` como vigência, prazo único de contratação ou garantia incondicional de preço.
8. **Pintura:** a resposta exige pintura nova e previsões no contrato/laudo para aceitação dessas coberturas. A presença de pinturas no fixture não define a contratação incondicional para todos os leads; apresentar condições junto às coberturas pertinentes.

`biometrias` é um campo novo em relação ao template inicialmente estudado: objeto indexado por CPF, com booleano true no exemplo aprovado. Não é lista de pretendentes nem confirmação documentada de que o sistema executou uma biometria. Falta a definição formal do significado de true/false e do comportamento com múltiplos CPFs; o warning de condição biométrica permanece. Não expor as chaves CPF desnecessariamente no card.

#### Identificação da alternativa: correção do mapa histórico

O retorno aprovado contém somente `calculos.N`, `tpSugestao = "N"` e **`opcaoAssistencia = "Plano Completo"`**, com ordem `["N"]`. Isso contradiz a interpretação anterior que fixava N como Básica. A evidência real prevalece para este caso: manter a chave e a descrição retornadas, sem sobrescrever o nome a partir daquela tabela.

Não generalizar agora que N sempre significa Completa, nem deduzir os significados de P/O ausentes. O comando diagnóstico usou assistência Completa 26206. O padrão final **Sem assistência 26203** permanece e sua representação deverá ser validada com o payload final. Não somar alternativas nem tratá-las como companhias independentes.

#### Pagamentos: 36 opções do fixture, sem alteração da regra 30x

Há quatro chaves de meios de pagamento e metadados comuns:

| Meio | `qtdMaxVista` | Opções em `vista` | `qtdMaxPrazo` / `prazo` |
| --- | --- | --- | --- |
| `debito` | 12 | 12 alternativas, qtd 1–12 | 0 / null |
| `credito` | 12 | 12 alternativas, qtd 1–12 | 0 / null |
| `ficha` | 12 | 12 alternativas, qtd 1–12 | 0 / null |
| `faturado` | Não se aplica | O meio inteiro é null | Não se aplica |

Total conferido: **36 alternativas**, todas em `calculos.N`. As opções de débito e crédito são idênticas neste exemplo. As chaves `"1"` a `"12"` identificam alternativas com quantidades distintas; cada objeto fornece um único `vlPremPar`, e não uma lista das parcelas individuais. Isso corresponde à regra confirmada de usar diretamente o valor unitário retornado, igual para as parcelas da alternativa escolhida.

A presença de qtd maior que 1 dentro de **`vista`** prova que não podemos traduzir esse nome automaticamente como pagamento de uma parcela. Também não devemos exigir `prazo` sem verificar a representação do payload final. A intenção de negócio continua parcelamento em 30 vezes, com crédito sem imobiliária e faturado para o cenário de imobiliária aplicável; selecionar a combinação compatível, usando o `qtd` retornado. Não converter 12x em 30x nem dividir seu total para inventar preço.

O limite 12 mostrado por `qtdMaxVista` vale para esta resposta de diagnóstico, não para todo o produto. O usuário reafirmou o limite de 30 e a quantidade final 30; a tarefa futura é validar a resposta gerada com os campos/regras finais, não solicitar novamente a decisão de quantidade. Faturado null neste teste não elimina a regra de fatura, já que o fixture não implementa o vínculo final da imobiliária.

#### Valores observados e composição

Valores abaixo servem somente para descrever esta amostra; não são tabela de preços fixa:

| Meio / quantidade | `vlPremPar` | `vlPremTot` | `vlJurTot` | `vlIofTot` |
| --- | --- | --- | --- | --- |
| Crédito ou débito, 1 | 693,48 | 693,48 | 0 | 47,66 |
| Crédito ou débito, 2 | 346,75 | 693,48 | 0 | 47,66 |
| Crédito ou débito, 12 | 57,79 | 693,48 | 0 | 47,66 |
| Ficha, 5 | 148,41 | 742,01 | 45,21 | 50,98 |
| Ficha, 12 | 72,75 | 873,10 | 167,28 | 60,00 |

Em ficha, as opções de 1–4 não têm juros; de 5–12 retornam juros e totais diferentes. `pcJur` aparece como valores como 0.07, 0.088 e 0.259: preservar a representação até mapear sua apresentação, sem confundir com os pontos percentuais usados em comissão. A presença de ficha não comprova equivalência com boleto e não altera o meio escolhido para Tokio.

A soma dos prêmios de coberturas contratadas é 645,82; com o IOF total de 47,66 resulta em 693,48. Em ficha 5x, 645,82 + juros 45,21 + IOF 50,98 = 742,01, total reportado. Esses exemplos corroboram a regra já confirmada de não adicionar novamente juros/IOF ao `vlPremPar`; não são autorização para reconstruir parcelas de outras condições.

Arredondamentos observados: 2 × 346,75 = 693,50, enquanto o total informado é 693,48; em ficha 12x, 12 × 72,75 = 873,00, enquanto o total é 873,10. Preservar ambos os valores reportados. Isso não documenta uma parcela final diferente nem revoga a regra de parcelas iguais; não fabricar ajuste residual, média ou última parcela a partir dessa diferença.

Metadados de sugestão: `codFormaPagamento = V`, `codFormaCobranca = DC`, `qtdParcela = 12`; sugestão total 693,48, parcela 57,79, 12 parcelas, `sem juros`, `descSugestaoPagto = vista` e descrição de cobrança `no Débito ou Cartão de Crédito`. São valores sugeridos pela API para esse fixture. Não substituem a seleção final 30x nem o meio definido por origem do lead; não atribuir significado universal a V/DC fora da evidência recebida.

#### Coberturas, limites e diferenciais

O array contém **12 coberturas**, das quais três apresentam valores:

| Código / descrição | `valorPremio` | `valorIS` | `valorVerba` |
| --- | --- | --- | --- |
| 401 — Aluguel | 471,27 | 12000 | 400 |
| 410 — Pintura interna | 89,11 | 1200 | 400 |
| 425 — Pintura externa | 85,44 | 1200 | 400 |

As outras nove (13º aluguel, fundo de promoção, condomínio, IPTU, água, luz, gás, danos e multa) aparecem com valores null e `caracteristica = "Não Contratada"`. Não mostrar essas coberturas como contratadas gratuitamente; diferenciar null de zero. Há espaços finais em algumas descrições: é possível apará-los para exibição sem alterar o significado cadastral. Os campos de franquia são null neste exemplo; isso não comprova ausência de franquia em todos os cenários.

`valorLMG = 14400`, igual à soma das três importâncias seguradas desse caso. O aluguel possui IS de 12000 para verba 400 (relação 30), mas isso é cobertura, não preço nem prova de parcelamento em 30x. Não confundir LMG, IS, verba, `multiploLMI` e prêmio. O retorno contém 20 diferenciais de serviços (chaveiro, elétrica, hidráulica, manutenção e outros); pertencem à alternativa recebida, não a todas as ofertas futuras.

#### Pendências revisadas e próximas validações

**Resolvido por estes materiais:** exemplos de aprovação e recusa; frases exatas de decisão; coexistência de success e danger no recusado; códigos CAL/REC observados; cálculos vazios na recusa; formato de listas de mensagens e ordem; múltiplas opções de pagamento; campos monetários preenchidos; necessidade de corrigir a associação fixa de N e o significado presumido de vista. Quantidade final 30 e inclusão de encargos já estavam definidas e permanecem encerradas.

**Ainda necessário, sem reabrir regras fechadas:**

1. **Encerrado em T20:** não existe encaminhamento via API; não solicitar exemplo, endpoint de acompanhamento ou polling. Aplicar CAL/aprovado e demais códigos/recusado na resposta de negócio válida.
2. Executar e conferir o payload final de 30 parcelas, Sem assistência e vínculo de imobiliária quando aplicável. A verificação será da representação retornada e aceitação dos campos, não da escolha de quantidade feita pelo usuário. Não usar o fixture para revogar esses padrões.
3. Esclarecer o significado de `biometrias`, se necessário às ações que serão disponibilizadas. A reanálise foi definida em 28/10 como nova solicitação, sem endpoint dedicado; preservar a restrição da resposta anterior e validar o retorno da nova criação. Aprovação cadastral e emissão/aceitação definitiva continuam distintas.
4. Validar na implementação as decisões de entrada já confirmadas em T15/T17: Titular com participação 100 sem cônjuge, renda de 4 × aluguel por pretendente inclusive na conjunta, pinturaNova S nos cenários com pintura, mapeamento/validação das coberturas por origem (seleção já definida na regra geral de 28/10), validação das vigências de 30 meses já definidas e da associação Tokio da imobiliária conforme T21 (fonte e regra sem parceiro já definidas). O casal comercial PF já foi definido em 28/10: ambos Titulares, participação 50 cada e `respondeFinanceiramente = S` para ambos, individuais paralelas e conjunta somente após duas recusas, conforme seção geral. As respostas de teste não preenchem as demais decisões por inferência.
5. Documentos continuam para estudo/validação na etapa correspondente, conforme orientação anterior do usuário; estes arquivos não trazem PDF ou endpoint de impressos.

Verificação desta revisão: arquivos lidos integralmente, hashes/estrutura conferidos, 36 alternativas e 12 coberturas enumeradas, igualdade das opções débito/crédito e contas monetárias verificadas com decimal. Somente o estudo foi editado; não houve alteração nos comandos, no runtime, nos testes de aplicação ou chamadas à seguradora. Os futuros testes do adapter deverão cobrir recusa com success operacional, aprovação com warnings, mensagens conflitantes/desconhecidas, nulls de pagamento/cobertura e seleção do preço no payload final.

### T20. Decisão final do suporte: CAL aprova, demais códigos recusam — 28/10/2026

Fonte: print da resposta do suporte Tokio apresentada pelo usuário nesta data. O suporte esclareceu que **não existe encaminhamento para análise via API**: o cálculo retorna aprovado ou reprovado, e qualquer status diferente de CAL deve ser interpretado como recusa. Esta confirmação substitui a orientação anterior sobre CPF aleatório poder encaminhar para análise e as interpretações provisórias de T7, T12, T16, T18 e T19.

Regra para o futuro adapter, depois de validar a resposta HTTP e a estrutura do retorno:

| `cotacaoInfo.cdSitucCotac` | Resultado |
| --- | --- |
| `CAL` | Aprovado |
| Outro código de situação válido e não vazio | Recusado, incluindo REC; não depende de catálogo fechado dos outros códigos |
| Ausente, null, vazio ou de tipo inválido | Resposta insuficiente/falha técnica; não há um código de situação de negócio a interpretar |

A classificação não depende de encontrar `Cadastro aprovado.` ou frases específicas de reprovação. `mensagens.success`, `danger`, `warning` e `info` serão preservadas e filtradas para explicar o resultado, exibir avisos e apoiar diagnóstico; não substituirão a decisão do código. CAL com warnings continua aprovado; outro código com success operacional continua recusado. Divergências textuais são registradas sem inverter o resultado definido pelo código.

HTTP 401/403, erro de validação, indisponibilidade, timeout, HTML ou corpo inválido não são códigos de situação da análise: não aplicar a regra de recusa ao código HTTP ou ao valor null. Falhas técnicas permanecem distintas da recusa de crédito. Aprovação cadastral não equivale a emissão, cobertura em vigor ou eliminação das condições de biometria informadas anteriormente.

Consequências para a implementação futura:

- A resposta válida de `/cotar` encerra a decisão cadastral daquela solicitação Tokio como aprovada ou recusada. Havendo cônjuge, a rodada da companhia ainda deve consolidar as individuais e a eventual conjunta conforme a regra geral. Não criar estado de encaminhamento, Job de polling, webhook ou endpoint de acompanhamento para um encaminhamento inexistente.
- Estados locais de fila/processamento antes da resposta, falhas técnicas e PJ com atendimento manual continuam necessários; não são estados de encaminhamento da seguradora.
- `--cenario=encaminhada` e a observação equivalente no comando diagnóstico ficaram semanticamente desatualizados. O código ainda existe e usa CPF aleatório; não comprova suporte a encaminhamento. Ajustar em alteração posterior de código, sem tentar repetidamente gerar esse retorno.
- Reanálise permanece a regra de nova cotação/solicitação pelos endpoints de criação, com nova rodada e histórico; a confirmação de status não autoriza repetição automática de recusas. A exceção expressamente autorizada é uma única conjunta após as duas individuais recusadas, conforme a regra geral de cônjuges de 28/10.
- Mantidos os padrões de 30 parcelas, pagamento por vínculo, Sem assistência, comissão e demais campos. Esta confirmação trata somente da decisão cadastral.

**Pendência encerrada:** não falta mais exemplo de encaminhamento, catálogo para distinguir estados pendentes nem mecanismo de conclusão dessa etapa na Tokio. Permanecem as decisões de payload e validações técnicas já registradas, exceto as substituídas expressamente aqui.

Somente a documentação foi atualizada nesta entrega, sem alterar o comando, provider, Jobs ou testes e sem chamadas à API.

### T21. Imobiliárias parceiras, associação de códigos e alternativa de pagamento — 28/10/2026

**Fonte indicada pelo usuário:** `C:\Users\Usuario\Downloads\planilha_imobiliaria.xlsx`, contendo os códigos dos parceiros atuais do cliente. Nesta etapa foi registrada a ideia; o conteúdo, as colunas e as correspondências da planilha ainda não foram inspecionados ou validados.

**Sistema futuro solicitado:** um comando deverá consultar os cadastros de imobiliárias do banco e comparar seus nomes com os da planilha, para associar corretamente cada imobiliária ao seu código de parceiro Tokio. Reaproveitar os cadastros existentes e identificar os parceiros que ainda precisam de cadastro, evitando duplicações. A criação dos cadastros e a associação serão realizadas na etapa de implementação, não nesta atualização documental.

A conferência deverá distinguir correspondências inequívocas, nomes semelhantes/ambíguos e registros sem correspondência. Normalizar espaços, caixa e acentos para auxiliar a comparação, preservando os nomes originais. Semelhança textual isolada não comprova identidade: não associar automaticamente homônimos ou resultados ambíguos. A estrutura efetiva da planilha e eventuais identificadores adicionais serão examinados na implementação. Preservar o código externo como identificador, inclusive zeros à esquerda, e registrar a origem da associação. O código Tokio não é o ID local da imobiliária.

**Regra de envio e pagamento confirmada pelo usuário:**

| Situação da imobiliária | Identificação na requisição Tokio | Pagamento usado para exibição e comparação |
| --- | --- | --- |
| Cadastrada no sistema e corretamente associada ao parceiro da planilha | Enviar `codigoParceiro` associado e `tipoParceiro = 5`, conforme contrato dos headers | Faturado, utilizando o valor da parcela retornado pela API |
| Cadastrada no sistema, mas ausente da planilha | Fazer a análise sem `codigoParceiro`; omitir também `tipoParceiro`, pois não há parceiro a identificar | Cartão de crédito, utilizando o valor da parcela retornado pela API; segundo o usuário, a cotação não disponibilizará ficha faturada nesse cenário |

Esta é uma exceção específica da Tokio à regra geral de faturamento por origem de imobiliária cadastrada: cadastro local, sozinho, não garante faturamento. A ausência na planilha não bloqueia a análise nem transforma a imobiliária em não cadastrada no sistema; mantém-se a origem real do lead e muda a condição de pagamento Tokio. Não inventar um código, enviar o ID local como parceiro ou reutilizar o código de outra imobiliária. Essa exceção não muda Boleto/Fatura nas demais companhias.

Preservar a base de 30 parcelas e comparar diretamente o valor unitário final retornado para a condição correspondente. Se a opção esperada não vier na resposta, tratar a indisponibilidade explicitamente, sem fabricar preço ou selecionar silenciosamente outro meio de pagamento. Uma associação ambígua não equivale a ausência comprovada na planilha: o comando deverá destacá-la para conferência.

**Pendência de origem dos códigos resolvida:** a planilha indicada será a fonte para associação. Permanecem para a implementação a inspeção dos dados, o vínculo persistido, o comando de conferência/associação e a validação do retorno com e sem parceiro. Não foram alterados banco, cadastros, comandos ou chamadas à API nesta etapa.

### T22. Participação individual, renda por pretendente e pintura nova — 28/10/2026

**Três pendências de preenchimento encerradas expressamente pelo usuário:**

- **Comercial PF sem cônjuge:** `tipoVinculo = 21147` (Titular) e `percentualParticipacao = 100`. O casal mantém a regra já definida de dois Titulares com 50 cada.
- **Renda:** cada pretendente recebe `rendaFixaMensal` correspondente a **4 × o valor integral do aluguel**. Aplicar tanto nas análises individuais quanto na conjunta condicional. Não dividir a renda por dois nem reduzi-la em razão da participação de 50%. Exemplo: aluguel de R$ 1.000,00 implica renda informada de R$ 4.000,00 em cada objeto pessoal. Ambos mantêm `respondeFinanceiramente = S`.
- **Pintura nova:** enviar `pinturaNova = S` para os cenários com cobertura de pintura interna e externa, conforme orientação do usuário. Esse campo é distinto de `icPinturaInterna` e `icPinturaExterna`, que também recebem S nesses cenários. A seleção não comprova antecipadamente a aceitação das coberturas pela API.

**Preservação da exceção por origem:** leads do formulário tenant continuam com somente aluguel, sem solicitar coberturas de pintura ou demais adicionais. O padrão pinturaNova S não deve reativar essas coberturas; omitir o questionário opcional de pintura nesse fluxo de somente aluguel, validando sua aceitação na implementação.

As definições acima substituem as pendências anteriores sobre esses três pontos. Nenhuma alteração de código, formulário, banco ou chamada remota foi realizada nesta atualização documental.
