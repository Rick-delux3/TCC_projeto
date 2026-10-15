# Estudo das APIs de Fiança Locatícia

Levantamento original: 25/09/2026. Estado atual: preparação da integração Tokio; os dados de formulário e parte da montagem dos pretendentes já foram implementados, mas o provider remoto ainda não existe.

Atualização documental: 09/10/2026 — estudo consolidado da Tokio Marine na seção [Tokio Marine](#tokio-marine). As seções originais de Too/Pottencial abaixo preservam o levantamento de 25/09; referências a “código atual” nessas seções são históricas, anteriores às refatorações. Para as decisões posteriores sobre parcelas, PDF e reanálise, prevalece o contexto atualizado da seção Tokio. Esta atualização não implementa nem testa a integração.

Decisão posterior de 13/10/2026: a [regra geral de pagamento e comparação em 30 parcelas](#regra-geral-de-pagamento-e-comparacao-em-30-parcelas) substitui a regra anterior de “até 30x” para **todas as companhias**. É uma definição funcional; a implementação atual ainda não foi adaptada nesta entrega.

**Revisão de 15/10/2026:** consolidada a branch `feature/testes-com-Apis`, até `a72f029`, incluindo a validação de nascimento presente na árvore de trabalho e ainda não commitada. A seção T14 registra os esclarecimentos posteriores, as estruturas já disponíveis e as lacunas reais para iniciar a integração. As decisões do usuário, a implementação local e o suporte documentado pela Tokio estão identificados separadamente. Esta revisão altera somente este estudo; não ativa a seguradora nem comprova homologação.

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

### Acesso

- Base referenciada pela coleção: `{{pottencial_api_url}}`. O padrão de `services.pottencial.base_url` é `https://api-hml.pottencial.com.br`; o ambiente pode sobrescrevê-lo.
- `POST /oauth/v3/access-token`: Basic Auth com `client_id` e `client_secret`; sem body na requisição da coleção.
- Operações do produto enviam headers `client_id` e `access_token`. Não confundir com Bearer da Too.
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

- `percentualComissao` é `number`; o exemplo usa `0.18`. Valores de coberturas e `periodoIndAluguel` estão tipados como inteiros. Não assumir suporte a centavos ou mudar escala com base apenas no exemplo.
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
| `getQuotePdf` | GET `/fianca/proposta/cotacao/{numeroCotacao}/pdf` | Não consta; PDF de proposta do YAML não é PDF de cotação |

O comentário de `getProposalStatus` também difere da implementação: o código inclui `/v3/`. Para este estudo foi considerado o caminho executado.

O builder atual gera ficha com `cnpjCorretor`, `razaoSocialCorretor`, `pretendentes`, `locacao`. A cotação atual recebe `numeroFicha`, `inicioVigenciaContratoLocacao`, `finalVigenciaContratoLocacao`, `indiceDeReajusteAluguel`, `periodoIndenitario`, `percentualComissao` e `coberturas`. Portanto, não basta trocar URLs para adotar o fluxo do YAML.

O provider atual faz ficha → análise → status → cotação quando o código de status é 8, com consultas posteriores pelo job `SyncTooAnalysisStatusJob`. Essa interpretação de status é evidência do código local, não um catálogo confirmado pelo YAML.

## Lacunas relevantes para a próxima etapa

1. **Contrato Too divergente:** obter a documentação correspondente às rotas de ficha, status v3, reanálise e cotação atualmente utilizadas, ou decidir explicitamente pelo fluxo do YAML. Preservar o código até essa definição.
2. **YAML não parseia como entregue:** Symfony Yaml rejeitou a linha 856, `nome: {{nomeProprietario}}`, por placeholder sem aspas. Para estudar estruturas, os placeholders desse formato foram colocados entre aspas somente em memória. O arquivo original continua intacto. Isso não equivale a validação completa OpenAPI.
3. **Autenticação Too:** confirmar headers versus Basic Auth/formulário antes de alterar o service.
4. **Schemas e catálogos incompletos:** confirmar status, tipos de documentos, planos, coberturas, pagamento, vigências, formatos de datas, escalas monetárias e comissões. Valores exemplificados não são enumerações exaustivas.
5. **PDF da decisão nos dois resultados:** confirmar parecer em aprovação e recusa, sobretudo a finalidade do `/draft` na Pottencial e a disponibilidade do `/parecer` na Too. Os services atuais tentam interpretar JSON e preservam `raw_body`; não implementam um contrato específico para download.
6. **Repetição e processamento assíncrono:** não foi encontrado contrato de idempotência, política de retries, limite de chamadas, webhook ou prazo de conclusão nas operações estudadas. Criação, solicitação de análise, atualização e eventual reanálise não devem ser consideradas seguras para repetição automática sem confirmação.
7. **Análise e atualização Pottencial:** faltam operações explícitas ou evidência de como essas capacidades são atendidas pelo contrato de Fiança recebido. Não reutilizar endpoints de outros produtos por semelhança.

Ambos os services dependem de `features.insurance_analysis.enabled` e da flag da companhia. Usam timeout de 30 segundos na autenticação e 60 nas operações; normalizam respostas em `success`, `http_status`, `endpoint`, `url`, `response`, `raw_body`, `headers` e, quando aplicável, `payload`. Sucesso HTTP não comprova aprovação de crédito ou emissão. Há logs de respostas integrais; a próxima implementação deve considerar os dados pessoais presentes nesses retornos.

## Verificação e estado da entrega

Inventário extraído dos arquivos locais: 11 operações Pottencial e 13 Too, mais uma autenticação por companhia. Coleção JSON analisada por parser; YAML analisado após ajuste de placeholders exclusivamente em memória. Os endpoints deste documento devem permanecer rastreáveis aos arquivos fonte; as rotas exclusivas do service foram identificadas separadamente.

Entrega documental apenas, com escopo atualizado conforme orientação do usuário para as cinco capacidades e PDF em aprovação e recusa. Nenhuma integração foi executada ou modificada. Próxima ação depende do comando do usuário.

<a id="regra-geral-de-pagamento-e-comparacao-em-30-parcelas"></a>

## Regra geral de pagamento e comparação em 30 parcelas

Definida em **13/10/2026**, a pedido do usuário. Aplica-se a Too, Pottencial, Tokio e Porto, quando integradas, na página de análise, nos resumos e nos documentos. Esta seção prevalece sobre referências históricas a comparação por total ou a um teto de até 30 parcelas.

### Base de pagamento

- A condição padrão é **exatamente 30 parcelas**. O preço principal é **o valor de uma parcela dessa condição de 30x**, não o prêmio total nem o valor de uma condição com outra quantidade de parcelas.
- “Fatura de 30 vezes” define aqui a base de parcelamento. Não muda automaticamente a forma de pagamento de todos os solicitantes para `faturado`; permanecem as regras por solicitante e os meios de pagamento próprios de cada companhia.
- Solicitar 30 parcelas quando o contrato da companhia permitir configurar a quantidade; quando a API retornar opções, selecionar somente a condição cuja quantidade confirmada seja 30. Não inventar um campo de requisição nem assumir que LMI define o parcelamento.
- Não dividir prêmio total por 30 para fabricar uma opção que a seguradora não ofereceu. Não converter 12x, 24x ou outra quantidade em 30x. Não multiplicar a parcela de outro plano para simular essa condição.
- `multiploLMI = 30` continua representando meses de limite de indenização. Quantidade de parcelas, LMI, vigência do contrato e vigência do seguro são dados independentes; não definir vigência de 30 meses por causa desta regra.
- O preço deve ter moeda e periodicidade identificadas. Mostrar “30x de R$ ...” para BRL confirmado/configurado; só acrescentar “por mês” quando a periodicidade mensal estiver confirmada.

### Seleção da condição e do preço

| Informação | Regra comum |
| --- | --- |
| Quantidade | Exatamente 30, informada ou confirmada pelo contrato/resposta da companhia |
| Situação | Proposta aprovada com decisão confirmada; HTTP 2xx sozinho não basta |
| Valor principal | Valor positivo e confirmado de uma parcela da condição de 30x |
| Total | Conservar o total informado pela companhia em campo separado; não substituir pelo valor da parcela |
| Juros e IOF | Conservar discriminados e registrar se já integram o valor da parcela; evitar soma duplicada |
| Assistência, produto e coberturas | Manter associados à mesma condição escolhida |
| Entrada ou parcelas diferentes | Não representar como 30 parcelas iguais; explicitar a condição e retirar da comparação direta até haver regra equivalente |
| Validade e rodada | Utilizar oferta vigente da rodada atual; respostas antigas não substituem a atual |

Na **Tokio**, selecionar `calculos.{assistencia}.opcoesPagamento.{forma}.prazo.{opcao}` com **`qtd = 30`** e destacar **`vlPremPar`**. A chave da opção pode ser `"1"` e não deve ser confundida com a quantidade. Não substituir por `sugestaoValorParc` de outra condição.

Para Too, Pottencial e Porto, mapear os campos equivalentes conforme os respectivos contratos e respostas reais. Não atribuir a essas APIs os nomes de campos da Tokio nem usar `grossPremium`/prêmio total como se fosse parcela. A falta desse mapeamento é uma pendência técnica, não autorização para estimar preços.

### Formas de pagamento

- **Tokio / imobiliária:** `faturado`, modalidade `prazo`, quantidade 30.
- **Tokio / locatário:** `debito` ou `credito`, modalidade `prazo`, quantidade 30. Até definir escolha/prioridade entre os dois meios, apresentar as condições disponíveis sem escolher silenciosamente uma forma pelo cliente.
- **Tokio / proprietário:** forma de pagamento ainda não definida. Essa pendência não altera o padrão de 30 parcelas.
- **Demais companhias:** aplicar a forma equivalente efetivamente suportada e autorizada para o solicitante. A nova regra de quantidade não autoriza mudar automaticamente o meio de cobrança.
- Se faltar a forma exigida ou a condição de 30x, registrar a indisponibilidade; não mudar para outro meio ou quantidade sem decisão explícita.

### Comparação e destaque

1. A unidade de comparação é a **parcela confirmada de um plano de 30x**. Todas as ofertas participantes devem usar essa mesma quantidade e uma base monetária equivalente; não comparar prêmio líquido de uma com parcela bruta de outra.
2. Comparar propostas da mesma finalidade de locação, com moeda, vigência, periodicidade e escopo de cobertura compatíveis. Mesmo número de parcelas não torna produtos diferentes automaticamente equivalentes.
3. A proposta vencedora é a de **menor valor por parcela entre as ofertas elegíveis em 30x**. A seleção deve ser única e compartilhada entre página e preparação do PDF, sem repetir a regra em controllers ou Blade.
4. O destaque definitivo continua condicionado à conclusão do pacote. Análises pendentes não são ofertas vencedoras; falta de preço ou valor zero/null não representa gratuidade.
5. Uma oferta sem 30x pode continuar visível como resultado da companhia, com indicação “Sem condição de pagamento em 30x”, mas fica fora desse ranking. A ausência de parcelamento não altera uma aprovação para recusa ou falha técnica.
6. Se houver companhias fora da comparação, apresentar o destaque como **melhor parcela entre as ofertas comparáveis em 30x**, indicando as exclusões. Não afirmar que é a mais barata entre todas as companhias. Se nenhuma oferta for elegível, não há destaque.
7. Quando a exclusão decorrer de informação desconhecida, como composição de IOF/juros, identificar “Comparação indisponível para esta oferta” e o motivo. Não usar zero como fallback nem tratar desconhecido como condição de 30x inexistente.
8. Empate em centavos representa empate de preço; não afirmar vantagem exclusiva. A ordenação visual pode ser estável, mas não deve inventar preferência comercial. Para um PDF que exige uma única oferta detalhada, o critério de desempate ainda precisa ser definido.
9. O ranking é de parcela, não uma afirmação genérica de menor custo total. A composição da parcela deve ser confirmada antes da comparação entre companhias. Para Tokio, a decisão de usar `vlPremPar` está mantida, mas sua inclusão de juros/IOF ainda depende de confirmação.

### Página, documentos, persistência e efeitos no código

O card deve informar produto/assistência, situação, **30x**, valor da parcela e forma de pagamento. Total, encargos, coberturas e vigência ficam disponíveis para detalhamento. Na falta de dados válidos, usar mensagem de indisponibilidade, nunca um preço simulado.

Página, PDF próprio e resumo de envio devem usar a mesma condição de pagamento e a mesma seleção de melhor oferta. Preservar o detalhamento completo da oferta escolhida e os valores de parcela/quantidade das demais comparáveis. Cartas de recusa continuam exclusivas da recusa unânime; falha de comparação ou ausência de 30x não dispara cartas de recusa.

Persistir junto à rodada: quantidade, valor da parcela, total reportado, moeda, forma/modalidade, periodicidade, juros/IOF e sua composição, produto/assistência, coberturas, vigência e referência da opção remota. Não sobrescrever o histórico nem reinterpretar um snapshot antigo por total como se já fosse uma oferta em 30x.

**Implementação pendente:** `AnalysisQuoteSummary` precisa representar parcelas sem alterar o significado dos campos de total; `AnalysisQuoteComparison` precisa selecionar opções elegíveis em 30x e explicar exclusões; página e preparação dos documentos devem consumir essa mesma regra. A lógica atual que ordena por `price.total` e exige `gross_total` não foi alterada por este estudo. A futura mudança também deverá tratar resultados antigos sem dados suficientes e versionar o contrato/snapshot quando necessário.

Validações essenciais futuras: 30x confirmado; opção de 12/24x excluída; quantidade ausente; preço nulo/zero/inválido; meio de pagamento indisponível; juros/IOF de composição desconhecida; parcelas desiguais; moedas/vigências incompatíveis; empate; aprovação sem oferta de 30x; e seleção idêntica entre página e PDF. Não é necessária chamada remota para registrar esta decisão documental.

## Tokio Marine

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
8. Documento próprio: após conclusão e havendo aprovação, preparar PDF com detalhes das coberturas da melhor oferta e resumo de preços das demais, mantendo coerência com a seleção da página. Cartas de recusa das APIs são buscadas **somente na recusa unânime**, não em resultado misto nem em falha técnica. O PDF próprio permanece desativado até a view ter conteúdo.
9. Entrega por jobs, armazenamento privado e reaproveitamento por rodada. Destinatários: imobiliária vinculada → e-mail principal e setores, sem incluir usuários vinculados; imobiliária não vinculada/proprietário → e-mail do responsável pelo preenchimento; locatário → e-mail do lead. O efeito da nova regra de parcelas no conteúdo final do PDF deve acompanhar a normalização compartilhada.
10. Reanálise pretendida: editar dados do lead e iniciar nova rodada com os dados atualizados. O usuário propôs estender a criação de nova análise a todas as companhias em lugar de depender de edição/reanálise específica. É uma direção funcional a avaliar por provider, não prova de equivalência entre operações remotas e não autorização para remover etapas obrigatórias das outras APIs.

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
| `tpPessoa` | Alfanumérico | Obrigatório. Documentado somente `F`; decisão local posterior exige `J` para CNPJ, com suporte remoto ainda a confirmar em T14 |
| `icResidencial` | Alfanumérico | Obrigatório. Indicador S/N, mas a descrição ainda afirma somente `S` disponível; conflita com tabelas de PF não residencial |
| `dadosItem` | Objeto | Obrigatório |
| `dadosLocacao` | Objeto | Obrigatório |
| `pretendentes` | Lista de objetos no JSON | Obrigatório; tabela chama “Objeto”, descrição e exemplos mostram lista |
| `coberturas` | Objeto | Obrigatório, não array |
| `pcComissao` | Valor | Obrigatório; exemplo `15`, sem decisão de negócio definitiva para Tokio |
| `pcRemuneracao` | Valor | Obrigatório; exemplo `0` |
| `pcDesconto` | Valor | Obrigatório; exemplo `0` |
| `pcAgravo` | Valor | Obrigatório; exemplo `0` |
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
| `pinturaNova` | Alfanumérico | S/N, questionário para cobertura de pintura; tabela posterior opcional, anterior obrigatório |
| `complemento` | Alfanumérico | Opcional no material inicial e presente no template de entrada |
| `utilizaContratoPadrao` | Alfanumérico | Opcional, S/N, **depreciado** |
| `cobrancaNoCartao` | Alfanumérico | Opcional, S/N, **depreciado** |

`multiploLMI` possui os valores documentados 12, 18, 24 e 30 meses de limite máximo de indenização. A escolha do usuário é 30. Não confundir essa unidade com a quantidade de parcelas do prêmio. O cURL exemplifica um ano de contrato/seguro; não foi definida uma regra geral de vigência e não se deve estendê-la a 30 meses apenas por causa do LMI ou do parcelamento.

#### `pretendentes[]`

| Campo | Tipo | Obrigatoriedade e significado |
| --- | --- | --- |
| `cpf` | Alfanumérico | Obrigatório, CPF do pretendente |
| `pretendentePrincipal` | Alfanumérico | Obrigatório, S/N; o CPF principal é o titular segundo a regra do usuário |
| `nome` | Alfanumérico | Obrigatório |
| `residenteNoImovel` | Alfanumérico | Opcional, S/N |
| `respondeFinanceiramente` | Alfanumérico | Opcional, S/N |
| `nomeSocial` | Alfanumérico | Opcional |
| `estadoCivil` | Alfanumérico | Opcional no contrato; domínio recebido posteriormente e registrado em T5. Regras comerciais do builder em T14 |
| `rendaFixaMensal` | Alfanumérico | Tabela marca opcional, descrição condiciona envio a responsabilidade financeira S; regra de renda do sistema é 4 × aluguel |
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
| `tipoVinculo` | Numérico | Obrigatório para PF não residencial; representa o vínculo com a empresa |

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

Não foi recebida resposta preenchida dessa execução. Não se pode afirmar, com os materiais disponíveis, status de aprovação, valores reais, duração, código HTTP ou número de cotação gerado.

### T7. Retorno: identificação, estado e alternativas

#### Raiz e `cotacaoInfo`

| Campo | Tipo documental / formato observado | Uso |
| --- | --- | --- |
| `cotacaoInfo` | Objeto | Informações da cotação |
| `cotacaoInfo.sqCdCotac` | Numérico | Identificador remoto; conservar sem arredondamento/perda de precisão |
| `cotacaoInfo.cdSitucCotac` | Alfanumérico | Código da situação; catálogo de valores não fornecido |
| `cotacaoInfo.cdNgocoRsvdo` | Numérico | Código de negócio reservado; sem papel adicional confirmado |
| `cotacaoInfo.dtPrmroCallo` | Alfanumérico | Data do primeiro cálculo; preservar a grafia `dtPrmroCallo` |
| `cotacaoInfo.dtVldad` | Alfanumérico | Validade da cotação; não é vigência do seguro |
| `cotacaoInfo.token` | Alfanumérico | Token da cotação, confidencial; não é access token OAuth |
| `cotacaoInfo.abaInicial` | Objeto | `id` numérico, `descricao` e `rota` alfanuméricos; metadados de navegação, sem necessidade na página própria |
| `mensagens` | Objeto | `danger`, `warning`, `success`, `info`: alfanuméricos na documentação |
| `calculos` | Objeto no JSON | A tabela de raiz o chama alfanumérico, incompatível com o exemplo e subtabelas |
| `ordemApresentacaoCalculos` | Alfanumérico | Ordem de apresentação; não há garantia de ordenação por preço |
| `pcComissao`, `pcProLabore`, `pcDesconto`, `pcAgravo` | Valor | Percentuais retornados; entrada usa `pcRemuneracao`, saída usa `pcProLabore`, sem equivalência detalhada confirmada |

HTTP bem-sucedido, `mensagens.success` e presença de valores não provam aprovação de crédito. Guardar o estado bruto e só mapear para aprovação/recusa após obter o catálogo da Tokio. Mensagens são auxiliares; não criar classificação por frases vagas. Se houver estado intermediário, não concluir o pacote nem inventar consulta periódica via POST de cotação.

#### Alternativas de assistência em `calculos`

| Chave | Significado confirmado nas tabelas |
| --- | --- |
| `P` | Assistência Completa |
| `N` | Assistência Básica |
| `O` | Assistência Intermediária — letra O, não zero |

A print de pagamento usada pelo usuário é de `N`, portanto Básica, mesmo tendo sido inicialmente chamada de Intermediária na conversa. A regra de pagamento se aplica à alternativa selecionada, não exclusivamente a N. Não somar os valores de P/N/O e não contar três análises no pacote. Não presumir que a Básica seja sempre a mais barata.

**Pendência de compatibilidade:** a entrada definida é Sem assistência 26203, mas o retorno fornecido só mostra P/N/O. Não há chave confirmada de Sem assistência nem prova de como o resultado muda com 26203. Não escolher silenciosamente Básica, Intermediária ou Completa para substituir Sem assistência. Necessária uma resposta preenchida com a entrada escolhida.

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

Como todos os escalares de T-05 são `null`, os tipos acima vêm das tabelas, não de uma resposta real. Não converter `null`, campo ausente ou texto inválido em zero; não anunciar seguro gratuito ou melhor preço por ausência de valor.

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

As tabelas mostram somente a chave `1` com a descrição “Parcela 1 por ir até x parcelas”. Isso não documenta que haverá uma única chave em todas as respostas, nem um calendário de vencimentos, nem que a chave seja a quantidade. Ler as opções recebidas e selecionar a condição com `qtd = 30`. A decisão de 13/10 exige exatamente 30 parcelas para comparação; ela não comprova que a API ofereça essa condição em todo cenário.

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
| Imobiliária | `faturado` | `prazo` | Decisão expressa do usuário; aplicar conforme classificação do solicitante, não apenas existência de company_id |
| Locatário | `debito` ou `credito` | `prazo` | Formas autorizadas; prioridade/seletor/menor entre ambas ainda não definidos |
| Proprietário | Não definida | `prazo` como diretriz geral | Necessária decisão de forma antes de habilitar o cenário |

`ficha` e `vista` constam no contrato, mas não foram escolhidos para o fluxo. A descrição de `ficha` não basta para afirmar equivalência com boleto. Não fazer fallback silencioso de faturado para crédito/débito caso a API não retorne a forma exigida. A regra de destinatário do e-mail não resolve a forma de pagamento.

#### Regra de apresentação e seleção futura

1. Manter assistência, forma, modalidade, chave, quantidade e vigência vinculadas a cada preço; nunca combinar parcela de uma opção com coberturas de outra.
2. Usar o `vlPremPar` confirmado da condição elegível; não usar o menor número encontrado em qualquer parte da resposta. `sugestaoValorParc`, `valorPremio` de cobertura, LMG e IS não são substitutos automáticos.
3. Exibir “30x de R$ ...” somente para a opção confirmada de 30 parcelas. Não dividir arbitrariamente o total por 30 nem afirmar periodicidade mensal não confirmada. Condições de outra quantidade podem ser informadas como resultado, sem participar do ranking padrão.
4. A decisão atual é comparar **parcelas de condições de exatamente 30x**. Ofertas com menos ou mais parcelas ficam fora desse ranking; vigências, coberturas e bases monetárias precisam ser compatíveis conforme a regra geral. Não apresentar a menor parcela como prova de menor custo total. Manter total e encargos disponíveis para contexto.
5. Não há evidência de que `vlPremPar`/`vlPremTot` incluam ou excluam juros e IOF. Não somar `vlJurPar`/`vlIofParc` cegamente, nem denominar a parcela “final com tudo incluso” sem confirmar. A escolha do usuário de destacar `vlPremPar` permanece registrada independentemente dessa pendência.
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
| Solicitar análise | Nenhuma rota separada recebida | Confirmar se `/cotar` faz decisão cadastral final ou apenas cálculo; não inventar `/analisar` |
| Visualizar resultado | Resposta de `/cotar` tem situação e cálculos | Não há GET de consulta confirmado nem garantia de resposta final síncrona |
| Recalcular orçamento existente | POST `/cotar`, com header `sqCdCotac` descrito para recálculo e `token` de acesso à cotação | Corpo pode ter regras específicas; confirmar campos alteráveis, efeitos na decisão, ID e validade |
| Nova rodada após edição do lead | Proposta do usuário de criar nova análise | Separar de recálculo remoto; não reutilizar identificadores antigos inadvertidamente numa criação |
| PDF/carta de recusa | Menu Impressos, sem contrato fornecido | Dependência documental pendente; não confundir cotação/proposta PDF com carta de decisão |

Sequência proposta para integração, não executada: validar cenário e dados → fixar snapshot da rodada → autenticar → cotar → validar envelope/identificação/estado → persistir resultado apenas se a rodada ainda for atual → normalizar alternativas/preços → notificar a página após commit → consolidar pacote quando todos forem terminais → acionar entregas/integradores independentemente.

Preservar proteções existentes de `attempt_id`, histórico e concorrência. Um recálculo/nova cotação tardio não pode sobrescrever outra rodada. Reanálise de negócio (novos dados/nova decisão), recálculo remoto (mesma cotação) e retry técnico (falha de transporte) são ações distintas.

Não há política documentada de idempotência, rate limit, webhooks ou polling. Timeout pode ocorrer depois de a cotação ter sido criada; repetir o POST pode duplicá-la. Não inventar suporte a `Idempotency-Key`. Reenfileiramento deve respeitar a possibilidade de resultado remoto desconhecido, os limites da fila e o contrato que vier a ser confirmado. Deletar um registro local de teste não apaga uma cotação na Tokio.

#### HTTP, falhas e status de negócio

Os materiais não enumeram respostas HTTP nem catálogo de erros. A tabela abaixo é uma proposta defensiva para o cliente, **não documentação de códigos garantidos pela Tokio**:

| Resposta observada futuramente | Tratamento proposto |
| --- | --- |
| 2xx com JSON | Validar estrutura e situação; sucesso de transporte não significa aprovação |
| 202 ou situação pendente | Não finalizar como aprovada/recusada; definir mecanismo documentado de conclusão antes de automatizar |
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
| `app/Services/Insurance/AnalysisQuoteComparison.php` | Atualmente filtra aprovadas, exige `basis = gross_total`, total positivo, mesma moeda e períodos comparáveis; ordena por `price.total`. **Ainda não implementa comparação por parcelas** |
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
| Alta | `cdSitucCotac` sem catálogo; não há consulta/solicitação de análise separada confirmada | Obter exemplos de aprovação, recusa e pendência; confirmar se cotação já representa análise final e como concluir estados intermediários |
| Alta | Sem assistência 26203 versus retorno só P/N/O | Obter resposta real desse cenário para não exibir assistência/preço indevidos |
| Alta | Comparação atual por total versus nova decisão por parcela em exatamente 30x | Refatorar regra compartilhada posteriormente; quantidade e exclusão de outras condições já definidas em 13/10. Confirmar equivalência monetária/vigências/coberturas, política para débito/crédito e desempate para PDF |
| Alta para comercial | `icResidencial` diz somente S, mas catálogos e campos comerciais existem | Confirmar liberação e payload PF não residencial no ambiente/credencial contratados; não declarar suporte apenas por haver domínios |
| Alta para comercial | Decisão local `tpPessoa = J` versus documentação somente `F` | Confirmar aceitação do cenário PJ e da data de fundação calculada no ambiente contratado; a estrutura local não comprova esse suporte |
| Alta para comercial | Catálogo CNAE recebido, com mais de um código em algumas descrições | Definir seleção determinística e validada de um código ativo Tokio por atividade; não descartar códigos, escolher arbitrariamente o primeiro ou enviar a lista no campo numérico |
| Alta para comercial | Ramo obrigatório no HTML, nullable no Request; outros estados civis e comercial com CPF fora do builder empresarial | Fechar obrigatoriedade e cobertura de cenários no payload completo, com falha explícita para dados insuficientes. As regras S/N e Titular/Sócio dos cenários já definidos não estão mais em aberto |
| Alta | Inclusão de juros/IOF em `vlPremPar`/`vlPremTot` e formato real de valores | Confirmar composição e tratamento de arredondamentos; manter destaque no campo definido sem inventar total final |
| Média | Padrão de exatamente 30x definido, disponibilidade remota não comprovada | Confirmar a condição por meio de pagamento, periodicidade e relação com vigência; sem 30x a oferta não entra no ranking. Proprietário e escolha débito/crédito ainda pendentes |
| Média | Recálculo tem headers próprios apesar da mesma URL | Confirmar envio de `sqCdCotac`/`token`, campos alteráveis, reaproveitamento do ID, efeitos na análise e limites para nova cotação |
| Média | Tabelas divergem de exemplos em tipos e obrigatoriedade | Validar LMI, pintura, capital inicial e códigos numéricos/string. Regra local LMI 30 e capital 450000 só comercial está definida, mas não substitui validação remota |
| Média | Token tem `expire_in` no exemplo | Confirmar resposta OAuth real, validade/cache e segregação W/Y/produção |
| Média | Sem HTTP/errors/idempotência documentados | Obter retornos reais e orientação da Tokio para timeout, repetição, rate limit e consulta/reconciliação |
| Média | PDFs/recusas sem endpoints recebidos | Obter contrato das cartas somente para o fluxo de recusa unânime; não bloquear resultado local por ausência do documento |
| Média | Comissão, remuneração, pintura e vigência somente exemplificadas | Definir configurações de negócio; não cristalizar 15%, um ano ou indicadores S por copiar o exemplo |

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

**Pendência de envio:** a seleção do formulário identifica a atividade, não um código específico. Para atividades com vários códigos Tokio ativos, ainda não há regra implementada de resolução de um único `CNAE`. Preservar todos os códigos e definir o critério antes da montagem final; não usar automaticamente o primeiro, o menor, o ID da atividade, o CNAE fiscal de outra fonte ou a string com `|`. A tipagem numérica do payload deve ser aplicada pelo adapter Tokio ao código escolhido; não alterar o armazenamento textual do catálogo.

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

Permanecem as decisões já registradas: Casa 10001 para residencial, Sala comercial 21103 para comercial; Seguro Novo 26470 no PF residencial, Troca de local de sede 21114 no PF não residencial; Sem assistência 26203; `multiploLMI = 30`; `capitalInicial = 450000` somente no comercial. Esses padrões de payload não estão todos conectados a um builder completo. A data de fundação de sete anos, os 30 meses de LMI e as 30 parcelas são conceitos separados.

#### O que falta conectar para iniciar a Tokio

1. **Cenários e payload completo:** resolver CNAE múltiplo, ramo ausente e cenários não cobertos pelo builder parcial; confirmar J/comercial e uso da data calculada. Compor raiz, item, locação, pretendentes e coberturas nos níveis corretos, usando os helpers existentes e o mínimo residencial apropriado.
2. **Acesso remoto:** implementar configuração, autenticação, cliente HTTP e provider no padrão existente. `InsuranceProviderResolver` ainda resolve somente Too e Pottencial; não há serviço HTTP Tokio conectado. Confirmar contrato OAuth e respostas reais sem registrar segredos.
3. **Retorno e estados:** mapear `cdSitucCotac`, aprovação/recusa/pendência, identificadores e alternativas. Preservar a pendência de retorno “Sem assistência”: a tabela recebida identifica P = Completa, N = Básica e O = Intermediária; não tratar N como intermediária com base na descrição inicial da print.
4. **Pagamento e comparação:** manter exatamente 30x, `prazo`, `faturado` para imobiliárias e `debito`/`credito` para locatários; destaque em `vlPremPar` da opção cuja quantidade seja 30. A chave `"1"` não é garantia de 30 parcelas. `AnalysisQuoteSummary` ainda não tem ramo Tokio e `AnalysisQuoteComparison` continua exigindo `gross_total` e ordenando total: a refatoração coordenada da página/PDF permanece necessária. Prioridade débito/crédito, proprietário, encargos e desempate permanecem pendentes.
5. **Rodadas e reanálise:** seguir a direção de editar o lead e iniciar nova análise, mantendo histórico e proteção contra resposta obsoleta. Cotar e recalcular usam o mesmo POST, mas headers de cotação existente diferenciam recálculo. A proposta de padronização entre companhias não elimina etapas exigidas pelos demais contratos nem comprova que a orquestração atual já esteja adaptada.
6. **Documentos e conclusão:** obter contrato dos impressos/pareceres necessários e manter resultado, comparação e entrega independentes de falhas técnicas. Não inferir rota de consulta, PDF, polling ou contratação a partir de nomes de menus.

#### Verificação desta revisão documental

Foram confrontados as instruções e prints da conversa, o estudo anterior, o histórico Git, migrations, modelos, Requests, Controllers, serviços, builders, componentes de formulário e testes existentes. Os arquivos originais citados continuam disponíveis em Downloads; os hashes do PDF CNAE e do CSV foram conferidos com a identificação versionada. Não foi necessária nova transcrição do vídeo para registrar os esclarecimentos posteriores. Executado `php artisan test --compact tests/Unit/TokioCnaeCatalogTest.php`: **4 testes aprovados, 7.651 assertions**, sem carga no banco de trabalho; `git diff --check` também passou.

A revisão não executa chamada à seguradora, não altera código de aplicação nem aplica migrations/Seeder no banco de trabalho. As mudanças de nascimento já presentes foram preservadas. Testes locais e catálogo íntegro não substituem exemplos reais e confirmação dos pontos de contrato pendentes em T12.
