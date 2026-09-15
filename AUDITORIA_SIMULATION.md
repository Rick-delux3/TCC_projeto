**Auditoria e manual de correção — formulários de simulação**

Data: 09/09/2026. Escopo: alterações locais em `Lead`, novo `LeadEmpresa`, `TipoLocacao`, duas migrations de 09/09/2026 e os três formulários de `resources/views/simulation/forms`, incluindo validação e persistência utilizadas por eles.

Foi feita somente auditoria. Nenhum código da aplicação foi corrigido, nenhum Form Request foi alterado e nenhuma migration foi aplicada ao banco da aplicação. Este arquivo é o manual solicitado.

**Resultado principal**

A estrutura foi iniciada, mas as três melhorias ainda não estão implementadas de ponta a ponta. Os formulários locais continuam com CPF e cônjuge sempre visíveis, sem seletor residencial/comercial nem representante da empresa. Há também problemas independentes da futura inclusão das regras nos Form Requests.

**Inconsistências encontradas**

| Prioridade | Evidência | Problema e consequência | Correção |
| --- | --- | --- | --- |
| Alta | [Migration de leads](database/migrations/2026_09_09_111255_add_cnpj_tipolocacao_to_leads_table.php), linha 16; [saveLead](app/Http/Controllers/SimulationController.php), linha 568 | `tipo_locacao` é não nulo e não tem default explícito, mas o fluxo atual não o grava. No SQLite dos testes, inserções existentes falham. Também não há estratégia explícita para classificar registros anteriores. | Definir uma transição para os dados antigos e passar o valor validado na criação. Não presumir que todos os leads antigos são residenciais. |
| Alta | [Migration de empresa](database/migrations/2026_09_09_113524_create_lead_empresa_table.php), linha 18 | A FK `lead_id` não é única. O banco aceita várias empresas para o mesmo lead, apesar de o model usar `hasOne`. | Criar índice único em `lead_id`, mantendo a FK e a exclusão em cascata. |
| Alta | [Lead](app/Models/Lead.php), linha 29 | `descrever_atividade` existe no banco, mas falta no `$fillable`. Adicionar a validação e passar esse atributo para `fill`, `create` ou `update` ainda não será suficiente para salvá-lo por atribuição em massa. | Adicionar o atributo ao `$fillable`, junto ao tipo de locação. |
| Alta | [saveLead](app/Http/Controllers/SimulationController.php), linhas 568–710 | Não há gravação de `tipo_locacao`, `descrever_atividade`, CNPJ ou representante; não há chamada ao relacionamento `lead_empresa()`. O model novo não cria registros automaticamente. | Mapear os atributos do lead e persistir a empresa condicionalmente na transação já existente. |
| Alta | [Formulário locatário](resources/views/simulation/forms/tenant.blade.php), linha 57; [imobiliária cadastrada](resources/views/simulation/forms/registered-company.blade.php), linha 77; [imobiliária não cadastrada/proprietário](resources/views/simulation/forms/unregistered-company_landlord.blade.php), linha 56 | Os três ainda usam `name="cpf"`, rótulo CPF e não possuem campos de representante. No Request ativo, `cpf` exige 11 dígitos e o `after()` também valida CPF: um CNPJ digitado nesse campo é rejeitado. | Criar um contrato único para CPF/CNPJ e adaptar conjuntamente apresentação, normalização, regras e persistência. |
| Alta | Os três formulários acima; [JavaScript](resources/js/simulation.js), linha 1 | Não existem seletor `tipo_locacao`, campo `descrever_atividade` nem lógica de alternância. O JavaScript atual cuida apenas da escolha inicial de perfil. | Implementar os controles e a inicialização dos campos condicionais nos três formulários. |
| Média | Seletor de estado civil: locatário, linha 73; cadastrada, linha 88; não cadastrada/proprietário, linha 67 | Não existe a opção `separado`. Existem `solteiro`, `casado`, `uniao_estavel`, `divorciado` e `viuvo`. Os campos de cônjuge ficam sempre visíveis. | Adicionar `separado` e implementar a ocultação pedida. Não substituir silenciosamente `divorciado` por `separado`. |
| Alta | [Request ativo](app/Http/Requests/StoreSimulationLeadRequest.php), linha 82; [saveLead](app/Http/Controllers/SimulationController.php), linha 643 | Para solteiro, os campos de cônjuge são opcionais, mas continuam aceitos. A persistência cria cônjuge sempre que recebe algum desses valores, independentemente do estado civil. | Descartar campos não aplicáveis no servidor e condicionar a gravação ao estado civil; ocultar apenas no navegador não basta. |

**Pontos específicos do model e das migrations**

O nome singular `lead_empresa` está corretamente configurado em `$table` no [LeadEmpresa](app/Models/LeadEmpresa.php), linha 9. Os quatro atributos de `$fillable` correspondem às colunas da tabela. O par `hasOne`/`belongsTo`, a FK para `leads` e `cascadeOnDelete()` estão coerentes. Não é necessário pluralizar a tabela para o relacionamento funcionar.

O relacionamento efetivo esperado é **um lead com zero ou uma empresa**: zero quando o documento é CPF; uma quando é CNPJ. Quem precisa ser único é `lead_id`. Tornar o CNPJ globalmente único seria uma regra diferente, que poderia impedir novas solicitações legítimas da mesma empresa.

Faltam os retornos `HasOne` em `Lead::lead_empresa()` e `BelongsTo` em `LeadEmpresa::lead()`, exigidos pelas convenções do projeto. O nome `lead_empresa()` funciona; renomeá-lo é opcional, não uma correção funcional obrigatória. Os cases em maiúsculas de `TipoLocacao` também funcionam, embora a convenção fornecida prefira TitleCase.

A migration usa `TipoLocacao::cases()` para definir o enum do banco. Isso funciona hoje, mas vincula uma migration histórica ao conteúdo futuro do enum PHP. É mais previsível congelar `['residencial', 'comercial']` nessa migration e usar migrations posteriores para mudanças de domínio.

O nome `add_cnpj_tipolocacao_to_leads_table` sugere adicionar CNPJ a `leads`, mas seu conteúdo só adiciona tipo de locação e atividade. O CNPJ está na migration de empresa. Isso é uma inconsistência de nome, não uma coluna obrigatoriamente ausente em `leads`.

Os limites atuais são: atividade e nome do representante com 55 caracteres, CPF do representante com 11 e CNPJ com 20. Não são erros por si só. A próxima validação deve respeitá-los, ou os tamanhos precisam ser revistos antes. CPF com máscara não cabe na coluna de 11: armazenar o CPF normalizado como string, preservando zeros iniciais. A possibilidade de campos empresariais nulos deve ser alinhada à decisão sobre sua obrigatoriedade para CNPJ.

**Manual de correção, na ordem recomendada**

**1. Fixar o contrato dos dados.**

Tipo de locação e tipo de documento são decisões independentes. Um CPF pode solicitar locação comercial, e um CNPJ pode solicitar residencial. Não usar a seleção comercial para deduzir CNPJ, nem CNPJ para deduzir comercial.

| Condição | Interface esperada | Persistência recomendada |
| --- | --- | --- |
| Residencial | Campo de atividade ausente | `tipo_locacao = residencial`; atividade nula |
| Comercial | Exibir “Descrever atividade” | `tipo_locacao = comercial`; atividade validada |
| CPF | Campos de representante ausentes | CPF em `leads.cpf`; sem registro em `lead_empresa` |
| CNPJ | Exibir CPF e nome do representante | `leads.cpf` nulo; CNPJ e representante em `lead_empresa` |
| Solteiro ou separado | Campos de cônjuge ausentes | Sem novos dados de cônjuge; remover vínculo anterior apenas em atualização autorizada |

Recomenda-se chamar a entrada única de documento de `cpf_cnpj`, deixando explícito que não é a coluna `leads.cpf`. O servidor deve classificá-la e fazer o mapeamento acima. Manter o nome atual é possível, mas exige uma separação explícita antes da validação de CPF e da gravação. Apenas aumentar a coluna `cpf` não resolve a modelagem proposta.

Não misturar `nome_responsavel`/`cpf_responsavel`, que representam a empresa locatária, com `responsavel_nome`, `responsavel_tipo` e `responsavel_preenchimento`, que já têm funções distintas nos formulários. A imobiliária vinculada também não é a empresa locatária.

Antes de implementar, registrar as decisões ainda não especificadas: obrigatoriedade de atividade e representante; comportamento de divorciado, viúvo e estado civil vazio; a quem se referem estado civil e nome principal quando o documento é CNPJ. Exibir cônjuge apenas para casado/união estável é uma opção coerente com os placeholders atuais, mas amplia a regra expressamente solicitada.

**2. Corrigir a estrutura sem prejudicar registros existentes.**

Adicionar a unicidade de `lead_empresa.lead_id`. Se a tabela já contiver duplicações, tratá-las antes de criar o índice. Definir a transição de `tipo_locacao`: uma opção é permitir nulo para legado e exigir a escolha nos novos formulários; outra é preencher o histórico com informação confiável e só depois exigir não nulo. Um default residencial só deve ser adotado se essa classificação tiver respaldo na regra de negócio.

Se as migrations ainda não foram executadas em ambientes compartilhados, ajustar os arquivos novos pode ser suficiente. Se já foram aplicadas, criar migrations incrementais: editar o arquivo antigo não altera bancos que já o executaram. O status de aplicação no banco real não foi consultado nesta auditoria.

**3. Completar os models.**

Adicionar `descrever_atividade` ao `$fillable` de `Lead`, manter o cast de `tipo_locacao` e tipar os dois relacionamentos. Não copiar CNPJ para `leads.cpf` nem usar CPF do representante como se fosse CPF da empresa.

**4. Implementar a interface compartilhada.**

Reutilizar um partial para os dados de documento/representante/estado civil nos três formulários. A escolha residencial/comercial deve permitir apenas um valor; radios são mais adequados que dois checkboxes independentes, podendo manter a aparência desejada.

Implementar as três condições na carga inicial, após retorno com `old()` e a cada mudança. Para cumprir literalmente “o campo não existe”, renderizar os campos condicionais apenas quando aplicáveis. Se forem mantidos no DOM e apenas escondidos, desabilitar os inputs e limpar valores ao mudar a condição; remover também `required` quando inaplicável. O servidor deve aplicar a mesma regra independentemente do navegador.

Verificar especialmente as transições comercial → residencial, CNPJ → CPF e casado → solteiro/separado. Colar um documento, apagá-lo e voltar de um erro de validação também deve atualizar os campos corretamente.

Essas views são reutilizadas pelo preenchimento interno do corretor, conforme `SimulationController`, linhas 149, 230 e 233. As mudanças também aparecerão nesse fluxo.

**5. Na próxima etapa, atualizar o Form Request realmente utilizado.**

Os três endpoints públicos recebem `StoreSimulationLeadRequest`. A busca em `app` e `routes` não encontrou uso de `StorePublicLeadRequest` no fluxo auditado; alterar apenas esse segundo arquivo não corrigirá `simulation`.

No Request ativo, normalizar e validar o documento único; validar `tipo_locacao` contra o enum; tratar atividade, representante e cônjuge condicionalmente; incluir `separado` e mensagens específicas. Hoje existe uma regra separada de CNPJ, mas ela não classifica o conteúdo de `cpf`, não verifica seus dígitos verificadores e não causa persistência empresarial.

A regra existente [CpfOrCnpj](app/Rules/CpfOrCnpj.php) já valida CPF/CNPJ numéricos com dígitos verificadores e pode ser reaproveitada dentro desse formato suportado. Para `cpf_responsavel`, exigir especificamente CPF, evitando que uma regra genérica aceite CNPJ nesse campo.

Para campos que devem desaparecer, usar exclusão condicional dos dados validados, ou proibição se a escolha for rejeitar envios inconsistentes. `nullable` não exclui um valor preenchido. Ao ajustar cônjuge, revisar também o `after()` atual: ele consulta os campos originais e pode continuar gerando erros de CPF para campos excluídos pelas regras se não receber a mesma condição de aplicabilidade. A documentação do Laravel descreve [exclusão e validação condicional](https://laravel.com/docs/12.x/validation#conditionally-adding-rules).

Não foi presumido que os novos campos sejam obrigatórios apenas por precisarem aparecer. Caso essa seja a regra, adicionar `required_if`/`Rule::requiredIf` junto ao tratamento de exclusão.

**6. Completar a persistência centralizada.**

Em `saveLead()`, adicionar tipo e atividade ao conjunto de atributos; para CNPJ, criar/atualizar `lead_empresa()` com os dados validados; para CPF, manter ausência de empresa e remover dados empresariais anteriores nas atualizações autorizadas. Aplicar tratamento equivalente à atividade residencial e aos dados de cônjuge não aplicáveis.

Manter essas operações nas transações existentes. Preservar o retorno antecipado das linhas 597–598 quando o lead já existe e a submissão pública não pode atualizá-lo. Não colocar criação, atualização ou exclusão dos relacionamentos antes dessa proteção. O método já admite atualização intencional pelo corretor com `allowExistingUpdate: true`.

**7. Conferir os consumidores antes de liberar análise para os novos casos.**

São dependências encontradas a jusante dos formulários, não alterações solicitadas para esta auditoria:

- [SendLeadToLeadLoversJob](app/Jobs/SendLeadToLeadLoversJob.php), linha 756: os campos dinâmicos usam `lead->cpf` e não incluem CNPJ, representante, tipo de locação ou atividade. Mesmo que os novos dados sejam salvos, não serão enviados por esse mapeamento atual.
- [RentalGuaranteeQuotePayloadBuilder](app/Services/Insurance/Payloads/RentalGuaranteeQuotePayloadBuilder.php), linha 36: exige documento em `lead->cpf` e lança erro quando ausente. Um lead empresarial com CPF nulo não passa nesse caminho.
- [TooRentalGuaranteePayloadBuilder](app/Services/Insurance/Payloads/TooRentalGuaranteePayloadBuilder.php), linha 276: exige CPF de 11 dígitos. Não se deve substituir automaticamente o documento da empresa pelo CPF do representante.
- [InsuranceAnalysisService](app/Services/Insurance/InsuranceAnalysisService.php), linha 50: registra o produto como `fianca_locaticia_residencial`, sem consultar `tipo_locacao`.

Alinhar esses contratos antes de encaminhar PJ/comercial para análise. A compatibilidade das APIs externas não foi pesquisada nem testada. A análise automática é condicionada à feature flag em `dispatchLeadFlow()`; estes impactos dependem do fluxo habilitado.

**8. Validar a correção com cenários de aceitação.**

Cobrir os três formulários e os dois perfis do formulário compartilhado — imobiliária não cadastrada e locador — além do uso interno das mesmas views:

- As quatro combinações CPF/CNPJ × residencial/comercial.
- CNPJ cria exatamente uma empresa; CPF não cria empresa.
- Tentativa de segundo registro empresarial para o mesmo lead é rejeitada pelo banco.
- Solteiro e separado não persistem cônjuge, inclusive em envio manual de campos inaplicáveis.
- Mudanças de condição não conservam atividade, representante ou cônjuge indevidos.
- Documento inválido, CPF/CNPJ com máscara, zeros iniciais e limites de texto.
- Retorno com erro preserva escolhas e reabre somente os campos aplicáveis.
- Submissão pública repetida não altera lead existente; atualização interna autorizada continua funcionando.
- Falha ao salvar a empresa desfaz a criação do lead na mesma transação.
- Migration e criação de leads verificadas no mecanismo de banco usado no ambiente de destino, além do SQLite dos testes.

**Verificações executadas e limites**

`php -l` passou em `Lead.php`, `LeadEmpresa.php`, `TipoLocacao.php` e nas duas migrations novas.

Foi executado o arquivo existente com:

```text
php vendor/pestphp/pest/bin/pest tests/Feature/PublicSimulationLeadOverwriteSecurityTest.php --no-coverage
```

Resultado: **7 testes falharam, 0 assertions**, todos na preparação dos leads, com `NOT NULL constraint failed: leads.tipo_locacao`, usando SQLite `:memory:`. Portanto, essa execução confirma a incompatibilidade da nova coluna com as criações existentes nesse ambiente; ela não chegou a testar as proteções de sobrescrita nem comprova o comportamento do banco de produção.

Não foram criados testes novos nem executados testes de navegador, pois esta etapa foi restrita à auditoria. Os novos comportamentos não têm cobertura específica nos arquivos de testes de simulação examinados. Pint não foi executado para evitar formatar alterações do usuário durante uma revisão sem correções.

O Laravel Boost não estava disponível entre as ferramentas da sessão. Como referência de framework, foram consultadas as documentações oficiais do Laravel 12 sobre [relacionamentos](https://laravel.com/docs/12.x/eloquent-relationships#one-to-one), [migrations e índices únicos](https://laravel.com/docs/12.x/migrations#creating-indexes) e [validação](https://laravel.com/docs/12.x/validation). Os achados sobre a aplicação foram baseados no código local e na execução descrita acima.
