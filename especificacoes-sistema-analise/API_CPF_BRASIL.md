# CPF Brasil — contrato recebido e proposta de integração

Revisão: 30/10/2026. Documento de análise; integração ainda não implementada nem testada remotamente. Objetivo autorizado: obter nascimento pelo CPF, dispensando sua digitação nos formulários de simulation. Preservar a [arquitetura das análises](ARQUITETURA_ANALISES_SEGURO.md) e as regras do [estudo das seguradoras](ESTUDO_FIANCA_LOCATICIA.md).

## 1. Fontes e limites

Arquivos fornecidos pelo usuário, lidos nesta revisão:

- `requisicao-api-cpf-brasil.txt`: exemplo cURL com método, URL e autenticação.
- `resposta_success_200.txt`: exemplo de resposta positiva.
- `codigo_erro_api-cpf-brasil.txt`: estrutura genérica de erro; complementada pelos 13 arquivos específicos catalogados na seção 9. Nenhum deles informa status HTTP.

Os exemplos descrevem o contrato recebido; não comprovam disponibilidade, exatidão cadastral, limites, cobrança por tentativa ou comportamento de produção. A chave presente no cURL não deve ser copiada para este documento, código, testes ou frontend.

## 2. Requisição confirmada

| Elemento | Contrato |
| --- | --- |
| Método | GET |
| Base | `https://api.cpf-brasil.org` |
| Caminho | `/cpf/{cpf}` |
| CPF | String de 11 dígitos, preservando zeros iniciais |
| Autenticação | Header `X-API-Key` |
| Header do exemplo | `Content-Type: application/json` |
| Corpo | Não há corpo no exemplo GET |

O link textual `/cpf.` não é a rota de consulta: usar `/cpf/{cpf}`, conforme o cURL. Enviar também `Accept: application/json`. Configurar host fixo no backend; nunca aceitar uma URL enviada pelo navegador. Manter verificação TLS e impedir redirecionamentos para evitar encaminhar a chave a outro host.

## 3. Resposta de sucesso

| Campo | Interpretação |
| --- | --- |
| `success` | Booleano; exemplo positivo contém `true` |
| `data.CPF` | CPF consultado, como string |
| `data.NASC` | Nascimento em `DD/MM/AAAA`; campo necessário à integração |
| `data.NOME` | Nome cadastral; não sobrescrever automaticamente o nome informado neste escopo |
| `data.SEXO`, `data.NOME_MAE` | Não necessários ao objetivo; não persistir ou devolver ao navegador |
| `meta.query_time` | Data/hora da consulta no exemplo |
| `meta.api_version` | Exemplo `2.0`; não implica inserir `/v2` na URL |
| `meta.response_time_ms` | Tempo reportado pelo fornecedor |

HTTP 200 sozinho não basta. Exigir JSON com estrutura válida, `success === true`, CPF retornado correspondente ao consultado e nascimento válido. Não considerar `success: "true"` equivalente sem confirmação contratual.

Converter estritamente `data.NASC` de `d/m/Y` para `Y-m-d`. Conferir erros de parsing e igualdade da data reformatada com a entrada, evitando normalização silenciosa de datas impossíveis. Não usar parsing permissivo que aceite formatos não documentados.

Reutilizar `App\Support\BirthDate` para a regra vigente de **18 a 120 anos completos**, inclusive, com o fuso configurado no projeto. O sucesso de exemplo contém `30/11/1899`: em 30/10/2026 essa data está fora da faixa permitida. Ela demonstra a estrutura de resposta, não um nascimento elegível ou um fallback.

## 4. Erros e resultados locais

Estrutura inicial recebida: objeto com `error`, `message` e `code`. O catálogo posterior da seção 9 confirma códigos específicos, campos adicionais e uma resposta contendo somente `error`. Não exigir que todos esses campos estejam presentes para reconhecer uma falha. As associações entre códigos e status HTTP ainda não foram fornecidas.

Proposta: o service distinguirá resultado encontrado, nascimento inválido/inelegível, resposta inconsistente e indisponibilidade técnica. Usar “não encontrado” somente quando houver evidência contratual para esse resultado; não presumir que qualquer 404, erro ou corpo vazio signifique CPF inexistente.

- Timeout, conexão, JSON inválido, `success` ausente/falso e erro HTTP não autorizam inventar uma data.
- Tratar 401/403 como falha de acesso a investigar; não afirmar que significam falta de saldo sem documentação.
- Tratar 429/5xx como falhas técnicas, respeitando `Retry-After` quando aplicável. Inicialmente não repetir automaticamente consultas potencialmente cobradas até conhecer cobrança e limites.
- Mensagens públicas devem ser locais e claras, sem corpo remoto, chave, CPF completo ou detalhes de infraestrutura.
- Exemplo de mensagem: “Não foi possível obter a data de nascimento. Tente novamente em instantes.” Idade fora da faixa deve ter mensagem específica, distinta de indisponibilidade.

## 5. Situação atual do projeto

- Os três formulários compartilham `resources/views/simulation/partials/form-shell.blade.php`, que atualmente exige `data_nascimento` digitada.
- `StoreSimulationLeadRequest` normaliza e exige nascimento com `BirthDate::rules()`; precisará distinguir entrada do usuário de nascimento obtido internamente.
- `SimulationController` persiste `leads.data_nascimento` e nome/CPF do cônjuge. A consulta deverá atender também os caminhos administrativos dos mesmos formulários.
- Existe `app/Services/Lookup/CpfLookupService.php`, voltado à CPFHub. Contém fallback fictício, parsing permissivo, formato `Y/m/d` e logs de resposta em debug. Esses comportamentos não devem ser transportados para CPF Brasil.
- A busca por consumidores identificou testes do serviço antigo, mas o builder Too atual usa nascimento persistido no lead; não consulta CPFHub durante a montagem.
- `InsuranceLeadSnapshot` já lê `data_nascimento` do cônjuge ao restaurar o cenário spouse. A persistência desse nascimento deve ser implementada e verificada antes de ativar o fluxo.

## 6. Arquitetura proposta

Manter a integração no domínio `app/Services/Lookup`. Refatorar o serviço existente para a nova finalidade, evitando dois serviços paralelos de nascimento e um método genérico acoplado ao nome da seguradora (`birthdateForToo`). Uma operação como `lookupBirthdate(string $cpf)` deve devolver nascimento normalizado e resultado tipado, sem expor o corpo completo do fornecedor.

Injetar o serviço no construtor de `SimulationController` pelo container Laravel, conforme solicitado. O controller coordena validação, consulta e persistência; HTTP, parsing, cache e tradução dos erros ficam no service. Não instanciar com `new` dentro de cada action nem repetir integração nas três actions.

Fluxo recomendado para a primeira implementação:

1. Form Request autoriza e valida os dados enviados, incluindo CPF e dígitos verificadores. Não exigir nascimento digitado nem confiar em nascimento vindo de hidden input.
2. Controller identifica os CPFs das pessoas realmente analisadas e chama o serviço uma vez por CPF distinto, após validação e **antes de abrir a transação de gravação**. Não manter locks de banco durante HTTP externo.
3. Service consulta/cacheia o nascimento, verifica identidade da resposta, formato e idade. Controller associa o resultado ao CPF correspondente.
4. Com os resultados válidos, persistir lead, responsável/cônjuge e dados necessários em transação. O nascimento do principal permanece em `leads.data_nascimento` conforme a semântica atual.
5. Após commit, manter `StartInsuranceAnalysesBatchJob` e o fluxo existente. Builders e jobs consomem os nascimentos persistidos no snapshot, sem novas consultas cadastrais por plano/seguradora.

A proposta é resolver no envio do formulário, sem criar inicialmente um endpoint público de consulta cadastral. O usuário continua informando CPF, mas não nascimento. Exibir estado de envio e impedir cliques duplicados. Uma pré-consulta ao concluir o CPF pode ser adicionada posteriormente se necessária, com endpoint próprio protegido e sem retornar dados pessoais completos.

### Qual CPF consultar

| Cenário | Consulta |
| --- | --- |
| Principal PF | CPF do principal |
| Lead PJ | `cpf_responsavel`, jamais o CNPJ; nascimento é da pessoa responsável |
| Cônjuge participante | CPF próprio do cônjuge |
| Imobiliária/proprietário apenas responsável pelo preenchimento | Não consultar por esse papel; seus dados são de contato |

Essa abordagem resolve a falta de nascimento do cônjuge com dado próprio e substitui a proposta de copiar a data do principal. As individuais e a conjunta usam os mesmos dados cadastrais obtidos antes da rodada. Rejeitar CPF de cônjuge igual ao principal/responsável conforme a validação existente.

## 7. Persistência, configuração e proteção dos dados

- Conferir o model/tabela do relacionamento `conjuge` e criar migration para nascimento se ausente; adicionar cast e persistência. Não criar migration nesta etapa documental.
- Registrar origem `cpf_brasil` e momento da obtenção, associados à pessoa/CPF, para rastreabilidade. Nunca reutilizar nascimento após trocar o CPF sem verificar a associação.
- Congelar os nascimentos na rodada antes das análises; consulta posterior não altera histórico de cotações concluídas.
- Configurar chave/base/timeout/cache em `config/services.php`, lendo `.env` apenas nessa configuração. Sugestão de variáveis: `CPF_BRASIL_API_KEY`, `CPF_BRASIL_BASE_URL`, `CPF_BRASIL_ENABLED`; exemplos versionados sem credenciais.
- Cache apenas do mínimo necessário, protegido, com chave derivada por HMAC do CPF e segredo local; não usar CPF puro ou hash simples como proteção suficiente. Revalidar a idade ao consumir o cache. TTL deve ser configurável e compatível com a política de retenção.
- Usar lock curto por CPF para reduzir consultas simultâneas duplicadas; não prometer cobrança exatamente uma vez diante de timeout remoto.
- Não guardar corpo integral, nome da mãe ou sexo. Não logar nascimento, chave, URL com CPF ou mensagens de exceção que possam conter esses dados, inclusive em debug. Logs limitados a categoria técnica, duração e status sanitizados.
- Aplicar limites ao envio público do formulário e ao número de consultas; sessão/CSRF e bloqueio visual de duplo clique não substituem rate limiting no servidor.
- Nunca chamar a API diretamente do JavaScript. Chave e resultados internos permanecem no servidor.

## 8. Decisões ainda necessárias e validação técnica

**Falha da consulta:** recomendação inicial é preservar o formulário e impedir o disparo das análises até obter nascimento válido, sem solicitar digitação e sem fallback fictício. Confirmar essa experiência com o usuário antes da implementação; alternativa possível é aceitar um cadastro pendente para retomada, o que exige estado e fluxo próprios.

**Contrato do fornecedor a completar:** status HTTP correspondentes aos erros, limites efetivos do plano contratado, cobrança por tentativa/retry, SLA e condições de retenção/cache. Os códigos de erro/CPF não encontrado foram esclarecidos pelos arquivos da seção 9. Definir timeouts finitos e política conservadora enquanto não houver confirmação; não copiar regras da CPFHub.

Testes necessários na implementação, com HTTP simulado e sem consumir créditos: sucesso válido; zeros iniciais; CPF inválido sem chamada; CPF de resposta divergente; data impossível/futura; limites 18/120; amostra de 1899; falha HTTP/timeout/JSON inválido; cache sem repetição; troca de CPF; principal/responsável/cônjuge; três formulários e acessos administrativos; nenhuma análise iniciada com nascimento ausente; snapshots individuais/conjuntos; ausência de dados pessoais nos logs.

Somente depois realizar homologação autorizada com CPF de teste fornecido para essa finalidade. O arquivo de exemplo não autoriza consultar pessoas aleatórias. Esta revisão não fez chamadas remotas, não consumiu créditos e não alterou services, controllers, Requests, banco ou views.

## 9. Catálogo de erros recebido em 30/10/2026

Foram lidos os 13 arquivos abaixo em `C:\Users\Usuario\Downloads`. O usuário os apresentou como o catálogo completo da API. São exemplos de corpos JSON, não capturas dos status HTTP; não inferir 400/401/403/404/405/429/500 a partir dos nomes. Manter tratamento defensivo para códigos novos, campos ausentes e respostas não JSON.

| Arquivo | `code` exato | Significado informado | Tratamento recomendado |
| --- | --- | --- | --- |
| `chave-ausente.txt` | `MISSING_API_KEY` | Chave ausente; mensagem aceita header ou parâmetro key | Falha de configuração. Impedir chamada localmente se a chave estiver vazia; usar somente header |
| `nao-autorizado.txt` | `INVALID_API_KEY` | Token inválido **ou usuário inativo** | Falha de acesso; não concluir que seja exclusivamente chave errada. Diagnóstico administrativo |
| `token-expirado.txt` | `TOKEN_EXPIRED` | Token expirado; mensagem informa renovação a cada 30 dias | Exigir manutenção da credencial; não repetir com a mesma chave nem inventar refresh endpoint |
| `plano-expirado.txt` | `PLAN_EXPIRED` | Plano expirou | Renovação administrativa; nova tentativa idêntica não resolve |
| `plano-offiline.txt` | `PLAN_SUSPENDED` | Plano suspenso | Regularização administrativa; não tratar como simples falha transitória de rede |
| `Quota-excedida.txt` | `QUOTA_EXCEEDED` | Quota mensal excedida | Bloquear repetição automática imediata; aguardar regularização/reset conforme informação válida do fornecedor |
| `cpf-obrigatorio.txt` | `MISSING_CPF_PARAMETER` | CPF não foi recebido | Validar entrada localmente; se CPF válido foi enviado, investigar montagem/contrato da requisição |
| `cpf-invalido.txt` | `INVALID_CPF_FORMAT` | CPF deve conter 11 dígitos numéricos | Orientar correção do documento; manter validação local dos dígitos verificadores também |
| `cpf-nao-encontrado.txt` | `CPF_NOT_FOUND` | Nenhum registro para o CPF | Resultado cadastral não encontrado; não confundir com CPF matematicamente inválido ou pessoa inelegível |
| `metudo-nao-permitido.txt` | **Ausente** | `error` contém “Método não permitido. Use GET.” | Erro de integração; preservar GET. Sem código/status comprovado, classificar genericamente como falha de protocolo, sem depender apenas do texto |
| `erro-interno.txt` | `DATABASE_NOT_FOUND` | Banco do fornecedor não encontrado | Indisponibilidade do fornecedor; não indica problema no MySQL local nem CPF não encontrado |
| `erro-interno-servidor.txt` | `DATABASE_QUERY_ERROR` | Falha ao consultar banco do fornecedor | Falha técnica remota; preservar cadastro e separar de recusa de seguradora |
| `erro-interno-geral-banco.txt` | `DATABASE_ERROR` | Erro interno; mensagem sugere nova tentativa após alguns minutos | Falha técnica remota; possibilidade de recuperação, sem promessa de prazo e sem retry ilimitado |

### Campos adicionais e operação

- `QUOTA_EXCEEDED` apresenta `quota_used`, `quota_limit`, `reset_date` e `action`. Valores 10/10 e `2026-02-01` são exemplos, não os limites ou a próxima renovação do contrato atual. A data não especifica horário/fuso; não calcular automaticamente o instante exato de liberação.
- `TOKEN_EXPIRED` apresenta `token_age_days` (35 no exemplo) e `action`. A mensagem de renovação a cada 30 dias é informação do fornecedor recebida nesta revisão. Planejar manutenção administrativa da chave; nenhuma renovação automática foi documentada.
- `PLAN_EXPIRED` e `PLAN_SUSPENDED` também apresentam `action`. Esses textos direcionam ao painel do fornecedor; não são instruções para o sistema executar compras, upgrades ou abrir URLs arbitrárias.
- A mensagem `MISSING_CPF_PARAMETER` cita `?cpf=...`, enquanto o cURL original usa `/cpf/{cpf}`. Manter a rota do exemplo de requisição como proposta, registrando a divergência para homologação. Não adicionar fallback entre rotas que possa duplicar consultas cobradas.
- A mensagem `MISSING_API_KEY` admite parâmetro `key`, mas o projeto utilizará apenas `X-API-Key`, evitando credencial em URLs, históricos e logs.

### Classificação e mensagens da aplicação

O service deve interpretar o código estruturado conhecido e o status HTTP conjuntamente. Um corpo com `error` não é sucesso, mesmo se vier com HTTP 200. Uma resposta contraditória, por exemplo `success: true` junto de erro, deve ser tratada como inconsistente. Código desconhecido ou ausência de código não autoriza concluir CPF inexistente.

| Resultado local proposto | Códigos/situação | Mensagem pública sugerida |
| --- | --- | --- |
| Documento inválido | `INVALID_CPF_FORMAT` ou validação local | “Confira o CPF informado.” |
| Documento ausente | Validação local | “Informe o CPF.” |
| Não encontrado | `CPF_NOT_FOUND` | “Não foi possível localizar os dados desse CPF. Confira o documento informado.” |
| Integração indisponível | Acesso, plano, quota, banco remoto, protocolo ou rede | “Não foi possível consultar a data de nascimento no momento. Tente novamente mais tarde.” |
| Nascimento inconsistente/fora da faixa | Resposta positiva inválida ou idade fora de 18–120 | Mensagem específica já prevista na validação de nascimento; não apresentar como erro de CPF |

`MISSING_CPF_PARAMETER` retornado após envio local válido deve ser diagnosticado como falha de integração, sem pedir ao usuário que corrija um campo já preenchido. Mensagens devem identificar se o documento é do principal, responsável ou cônjuge, sem revelar CPF completo ou dados cadastrais adicionais.

Registrar somente código conhecido, categoria, status HTTP e contexto técnico sanitizado. Quota/reset podem ser usados em diagnóstico administrativo controlado; não expor saldo, plano ou credenciais aos solicitantes. Não registrar o corpo remoto integral nem classificar os erros de banco do fornecedor como falhas do banco da aplicação.

### Retry, cache e testes

Não repetir automaticamente falhas de chave, token, plano, quota, CPF ausente/inválido ou não encontrado. Para erros de banco, conexão e timeout, eventual retry deve ser limitado e condicionado às regras de cobrança ainda desconhecidas. Nenhum erro deve ser cacheado como nascimento válido ou substituído por data fictícia.

Na implementação, testar os 12 códigos fornecidos e a resposta sem código; incluir campos extras, códigos desconhecidos, HTTP contraditório, corpo não JSON e erro no segundo CPF do casal. Validar que nenhum desses resultados inicia análises com nascimento ausente e que mensagens/logs não revelam chave ou dados pessoais.

**Pendência encerrada:** catálogo dos erros de acesso, assinatura, quota, CPF e banco remoto. **Permanecem:** status HTTP reais, limites/cobrança do plano, confirmação da rota e decisão do usuário entre bloquear envio com preservação do formulário ou manter cadastro pendente quando a consulta não puder ser concluída. Nenhum código de aplicação foi modificado nesta revisão.

## 10. Backend implementado — 30/10/2026

O usuário confirmou a opção de token permanente ativada no fornecedor. Não programar renovação periódica de 30 dias para essa credencial; manter tratamento defensivo de TOKEN_EXPIRED caso o fornecedor o retorne.

- Feature flag única: `features.cpf_lookup.enabled`, configurada por `CPF_LOOKUP_ENABLED`. Chave lida de `CPF_BRASIL_TOKEN`, sem cópia para arquivos versionados.
- Ativa: Request exclui nascimento enviado pelo navegador; controller consulta principal/responsável e cônjuge antes da transação, persiste as datas e só depois mantém o fluxo de análises existente.
- Desativada: nenhuma consulta externa; nascimento principal manual obrigatório, com faixa 18–120. CPF sintético de 11 dígitos é permitido para homologação; CNPJ mantém validação. Essa opção não altera os validadores próprios de cada seguradora. Nascimento manual do cônjuge é aceito pelo backend como `conjuge_data_nascimento`, quando informado; não há cópia da data do principal.
- Serviço CPFHub substituído em `Services/Lookup/CpfLookupService`: sem fallback fictício, sem corpo remoto em logs, host fixo, sem redirects/retries automáticos, cache criptografado por chave HMAC e lock por CPF.
- Falha de consulta retorna validação no campo do CPF correspondente antes da gravação/disparo; não cria cadastro pendente.
- Migration adiciona `lead_conjugues.data_nascimento`, com cast date, permitindo ao snapshot existente restaurar a data própria no cenário do cônjuge.
- Views e JavaScript não foram modificados. Ocultar/exibir o campo conforme a flag e ajustar o comportamento visual ficam para a próxima etapa. A implementação de provenance persistida (fonte/data da consulta), sugerida na seção 7, não foi incluída nesta etapa; não confundir o cache de datas com auditoria histórica da consulta.
- Nenhuma consulta remota real foi executada; validação com HTTP simulado não comprova o contrato de produção ou saldo do fornecedor.