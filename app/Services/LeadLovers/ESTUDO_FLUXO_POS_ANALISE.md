# LeadLovers após o pacote de análises

## Escopo e regra de negócio

Auditoria de 04/11/2026, sem refatoração, reenvio ou alteração de dados. Este arquivo registra a regra solicitada e as evidências locais; não representa homologação remota.

- Aguardar o encerramento das análises do pacote/rodada vigente.
- Havendo pelo menos uma aprovação, criar o lead na LeadLovers com a tag de aprovado já no payload de criação.
- Somente quando todas as seguradoras recusarem, criar com a tag de recusado. Falha técnica não equivale a recusa.
- Lead já identificado remotamente deve sincronizar o resultado, sem criar outro cadastro.
- A falha da integração não deve apagar o resultado local das seguradoras.

## Fluxo existente

1. InsuranceQuoteCoordinator consolida cenários/cotações por seguradora e solicita a conclusão do pacote.
2. CompleteInsuranceAnalysesBatchJob verifica a rodada atual e o encerramento de todas as execuções. Consolida analysis_final_status, analysis_final_tag_key, analysis_finalized_at, last_analysis_batch_id e tags_originais.
3. InsuranceBatchResult prioriza approved após o encerramento; rejected exige recusas unânimes. Falhas e necessidade de análise manual não recebem tag de recusado automaticamente.
4. queueLeadLoversSync verifica a integração habilitada e readyForLeadLovers. O evento leadlovers_final_sync_queued evita duplicar o disparo da mesma rodada.
5. Sem ID remoto, dispara SendLeadToLeadLoversJob na fila leadlovers; com ID, dispara ApplyFinalAnalysisTagToLeadLoversJob.
6. SendLeadToLeadLoversJob valida máquina/sequência e tags, monta staticFields, tags e dynamicFields e chama LeadLoversApiClient::createLead: POST /leads/. O contrato implementado espera HTTP 200, success=true e leadId positivo.
7. creationPayload inclui o ID da tag final ativa. O mapeamento local é approved → aprovados e rejected → ruim, cujo rótulo de negócio é Recusado. Os nomes não são enviados literalmente como tags: são enviados IDs do catálogo.
8. Após criação, persiste o ID remoto e sincroniza/confirma máquina e sequência. Somente a confirmação completa marca sent. Conciliação de resultado e e-mail têm caminhos separados.

## Responsabilidades dos serviços

- LeadLoversApiClient: transporte, autenticação, limitação local, validação de respostas e erros seguros.
- LeadLoversLeadResolver: busca e identificação inequívoca por e-mail; não associa pessoas apenas por telefone.
- CompanyTagService: catálogo e vínculo de tags de imobiliárias.
- LeadLoversResultTagService: valida catálogo final e prepara aplicação/remoção de tags de resultado.
- LeadLoversTagOperationCoordinator: coordena operações de tags, versões, confirmação e concorrência entre resultado manual e automático.
- LeadLoversInitialFailureRecoveryService: correções autorizadas de falhas HTTP 400 e novo disparo, com bloqueio concorrente e sem recriar cadastro que já tenha ID remoto.
- LeadLoversConfirmationRecoveryService: recuperação de confirmação de máquina após criação; não resolve conflito PHONE_EXISTS na criação.

## Evidência do lead 2 em 04/11/2026

Consulta local somente de leitura:

- Pacote 2: completed_with_errors; Too approved, Pottencial failed e Tokio failed. Todas as execuções encerradas.
- Resultado local: approved; tag final aprovados; ID remoto nulo.
- Evento local_result_consolidated registrado, seguido de leadlovers_final_sync_queued.
- LeadLovers: failed; operação lead_creation; HTTP 400; código PHONE_EXISTS.
- failed_jobs contém SendLeadToLeadLoversJob para leadId=2 na fila leadlovers. Não havia jobs pendentes na consulta.
- Tags locais aprovados e ruim estavam ativas com IDs remotos positivos.
- Integração e módulo de análises habilitados; conexão da fila database.
- E-mail possui impedimento separado: view do PDF próprio sem conteúdo. Isso não impediu o disparo LeadLovers.

Conclusão: houve conclusão, disparo, execução e tentativa de criação. O bloqueio observado é o conflito de telefone informado pela API, não a ausência do job de envio. A identidade do cadastro remoto conflitante não foi consultada nem confirmada.

## Tratamento atual e recomendações para a próxima etapa

O job concilia EMAIL_EXISTS por e-mail. PHONE_EXISTS segue permanentlyFail, registra os metadados e encerra o job. O catálogo de falhas permite corrigir telefone e reenviar; repetição automática sem resolver o conflito não foi implementada. O evento de sincronização já enfileirada impede que concluir novamente o mesmo pacote provoque outro envio; a recuperação precisa usar seu fluxo específico.

Não alterar telefone verdadeiro para contornar unicidade nem vincular um cadastro remoto somente porque compartilha o telefone. Se for desejada conciliação desse conflito, primeiro definir a identificação segura do cadastro existente e validar as possibilidades do contrato remoto.

A print de aguardando envio/sem resultado não corresponde ao estado persistido encontrado nesta auditoria. Conferir atualização da tela e entrega dos eventos; não atribuir causa a cache ou websocket sem reproduzir no navegador.

Preservar o fluxo de conclusão e a inclusão da tag na criação, que já existem. Priorizar diagnóstico visível do conflito, recuperação segura e atualização do painel. Qualquer refatoração aguarda autorização do usuário.
