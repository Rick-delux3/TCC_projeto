# Diretrizes do Projeto — NVS / Aki Aluga (Laravel 12)

Plataforma de gestão de leads, imobiliárias e orquestração de seguro fiança locatícia (Pottencial, Too Seguros, Tokio Marine e Porto Seguro).

---

## 1. Stack Tecnológica & Ambiente
- **Backend:** PHP 8.2+ (XAMPP/Windows), Laravel 12.
- **Banco de Dados:** MySQL em execução real; SQLite `:memory:` para testes automatizados.
- **Filas & Realtime:** Laravel Queue (`QUEUE_CONNECTION=database` na mesma conexão da aplicação), Laravel Reverb, Echo 2.
- **Frontend:** Blade, Alpine.js 3, Tailwind CSS 3 e Bootstrap 5.3.8 (via CDN em telas do dashboard), Vite 7.
- **Testes & Estilo:** Pest 3, PHPUnit 11, Laravel Pint.

---

## 2. Comandos de Desenvolvimento
- **Dev Server completo:** `composer dev` (inicia HTTP, Vite, Reverb e workers).
- **Compilação de Assets:** `npm run dev` (dev) ou `npm run build` (produção/teste).
- **Testes Pontuais:** `php artisan test --filter NomeDoTeste` (sempre rode testes direcionados).
- **Formatação de Código:** `php vendor/bin/pint --dirty` (apenas nos arquivos alterados).
- **Seed de Catálogo:** `php artisan db:seed --class=TokioCnaeSeeder --no-interaction`.

---

## 3. Arquitetura de Seguros (`app/Services/Insurance/`)
- **Orquestração central:** O fluxo passa por `StartInsuranceAnalysesBatchJob` -> `InsuranceQuoteCoordinator` -> `RunInsuranceQuoteJob`.
- **Hierarquia:** Pacote (`InsuranceAnalysisBatch`) -> Análise da Cia (`InsuranceAnalysis`) -> Rodada (`InsuranceAnalysisRound`) -> Execução (`InsuranceProviderRun`) -> Cotação (`InsuranceQuote`).
- **Não crie orquestradores paralelos.** Use os adapters existentes em `Providers/` e os construtores em `Payloads/`.
- **Cenários de Pretendentes:**
  - `primary` e `spouse` rodam em paralelo para PF.
  - Análise conjunta (`joint`) **só é disparada se ambas as individuais forem recusadas** na mesma companhia/rodada.
  - PJ usa exclusivamente o cenário `primary` com o CNPJ (sem análise de cônjuge).
- **Reanálise:** Não existe endpoint de reanálise de negócio; ela consiste em **gerar uma nova rodada e novas cotações**, mantendo o histórico intacto.

---

## 4. Regras Gerais de Negócio
- **Vigência:** 30 meses a partir da rodada, sem overflow de mês.
- **Pagamento padrão:** Sem entrada = 29 parcelas (base 30 meses). Com entrada = 30 parcelas.
- **Comissão padrão:** 15% (fração 0.15 na Pottencial; 15 pontos percentuais na Too, Tokio e Porto). Demais adicionais = 0.
- **Formas de Pagamento por Companhia:**
  - **Pottencial:** Invoice (Fatura) se imobiliária cadastrada; Boleto para as demais.
  - **Too Seguros:** Fatura (`tipoPagamento=2`) se cadastrada; Boleto (`tipoPagamento=1`) para as demais (ambas Mensal).
  - **Tokio Marine:** Faturado somente com código parceiro válido; caso contrário, Cartão de Crédito.
  - **Porto Seguro:** FAT se cadastrada com código Porto; CC para as demais.
- **Formulário Locatário:** Envia exclusivamente o valor do aluguel (sem encargos ou coberturas acessórias).
- **Demais Formulários:** Incluem despesas preenchidas e coberturas de pintura/danos/multa. Se água/luz forem removidas pelo usuário, persistir zero (não reinjetar valores padrão).
- **Catálogo de Atividades:** Pesquisa é feita exclusivamente por **descrição** (nunca por código CNAE).

---

## 5. Regras Específicas das Seguradoras
- **Pottencial:** Usa a rota única que aceita CPF/CNPJ em `participants[].documentNumber`. Executa 3 planos (`Basic`, `Complete`, `Traditional`).
- **Too Seguros:** Status 8 e 16 liberam cotação; status 5 indica em análise. Limite de polling definido em `TOO_STATUS_CHECK_MAX_PENDING_CHECKS` (se atingido, estado vira `awaiting_provider`, sem transformar em recusa).
- **Tokio Marine:** Aceita apenas **PF** via API. Se PJ, marcar `manual_required` (não enviar o CPF do responsável como se fosse PF). Decisão via API: `cdSitucCotac = CAL` aprova; qualquer outro código recusa.
- **Porto Seguro:** Provider `porto` registrado no resolver, desativado por padrão (`PORTO_ENABLED=false`) até concluir a homologação. Arquivos: `PortoService` (HTTP), `PortoQuoteResponse` (interpretação), `Payloads/PortoQuotePayloadBuilder` e `Providers/PortoInsuranceProvider`.
  - **Transporte:** `PORTO_BASE_URL` guarda só a base; o service completa `emissao/aluguel-essencial/v1`. Token OAuth reutilizado em cache cifrado (`PortoAuthentication::token()`), enviado em `Authorization: Bearer` e `access_token`. Um 401 renova o token e reenvia uma única vez.
  - **Fluxo:** POST `/orcamentos` (uma criação por cenário) -> PUTs simultâneos de comissão por oferta (`{"ofertas":[{"seguro":"<código>","comissao":{"codComissao":15}}]}`) -> GET de ofertas somente após todos os PUTs (`PORTO_PRICE_SOURCE=budget` usa o GET do orçamento). Falha na etapa de preços é repetida sem recriar o orçamento.
  - **Decisão:** HTTP 201 aprova; 409 recusa, com o orçamento em `detailedMessage`. 400/401/500/502 e timeout são falha técnica, nunca recusa, e não autorizam a conjunta. Orçamento é string de 15 dígitos.
  - **Payload:** `susep` (`PORTO_SUSEP`), `cpfCotador` (`CPF_COTADOR`), reajuste "002", vigência "1". CPF residencial = `tipoLocacao` "1" e renda 4× aluguel; comercial e todo CNPJ = "2", renda 10× aluguel e capital de abertura 100× aluguel. PJ: só a empresa (CNPJ + razão social), capital social 450000, motivo "A" fixo no residencial; comercial usa `motivo_contratacao` ("1" a "9"). Cônjuge usa os contatos do principal.
  - **Coberturas:** locatário só 001; demais formulários 001 + encargos 002–006 positivos (até o aluguel) + 007–010 com IS igual ao aluguel.
  - **Preço:** **FAT** somente com `porto_partner_code` (enviado em `imobiliaria`); **CC** para as demais. Seleciona `valorParcela` da condição de 29 pagamentos Sem entrada. Somente a oferta **0008 (Fiança)** com comissão confirmada entra na comparação; Essenciais (0001, 0005, 0007) ficam como alternativas. Aprovação sem Fiança elegível continua aprovada, com preço indisponível.
  - **Documentos:** carta de recusa via `ProviderRefusalLetterService`, uma por cenário recusado com orçamento válido; PDF da cotação via `PortoInsuranceProvider::quotePdf()` para a oferta, forma e condição selecionadas (ainda não anexado ao e-mail/painel). `data.file` em Base64 validado como PDF; falha documental não altera a decisão.

---

## 6. Convenções e Cuidados
- **Não vaze dados sensíveis:** Payloads, tokens e logs de transporte devem passar por sanitização via `InsuranceDiagnosticSanitizer`.
- **Fila Database:** Manter o coordenador sob fila `database` transacional. Não mudar para Redis/SQS sem validação.
- **Escopo Cirúrgico:** Ao implementar uma tarefa, altere apenas os arquivos diretamente envolvidos e teste-os individualmente para evitar regressões nas outras seguradoras.