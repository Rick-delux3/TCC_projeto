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
- **Porto Seguro:** Integração em homologação (sem provider ativo no resolver). Fluxo previsto: POST orçamento -> PUTs simultâneos de comissão (15%) por oferta -> GET ofertas.

---

## 6. Convenções e Cuidados
- **Não vaze dados sensíveis:** Payloads, tokens e logs de transporte devem passar por sanitização via `InsuranceDiagnosticSanitizer`.
- **Fila Database:** Manter o coordenador sob fila `database` transacional. Não mudar para Redis/SQS sem validação.
- **Escopo Cirúrgico:** Ao implementar uma tarefa, altere apenas os arquivos diretamente envolvidos e teste-os individualmente para evitar regressões nas outras seguradoras.