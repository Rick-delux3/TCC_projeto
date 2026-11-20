# NVS / Aki Aluga (Laravel 12)
Plataforma de gestão de leads, imobiliárias e seguro fiança (Pottencial, Too, Tokio Marine, Porto Seguro).

## Stack & Comandos
- PHP 8.2+ (XAMPP/Windows), Laravel 12, MySQL (prod) / SQLite `:memory:` (testes), Queue `database`, Reverb, Echo 2, Blade, Alpine 3, Tailwind 3, Bootstrap 5.3.8 (CDN), Vite 7, Pest 3, Pint.
- Dev: `composer dev` | Assets: `npm run build` | Teste: `php artisan test --filter NomeDoTeste` | Lint: `php vendor/bin/pint --dirty`

## Arquitetura (`app/Services/Insurance/`)
- Fluxo: `StartInsuranceAnalysesBatchJob` -> `InsuranceQuoteCoordinator` -> `RunInsuranceQuoteJob`.
- Hierarquia: `InsuranceAnalysisBatch` -> `InsuranceAnalysis` -> `InsuranceAnalysisRound` -> `InsuranceProviderRun` -> `InsuranceQuote`.
- Use apenas adapters em `Providers/` e builders em `Payloads/` (sem orquestradores paralelos).
- Cenários: PF roda `primary` + `spouse` em paralelo; `joint` só se ambas recusarem na mesma cia/rodada. PJ roda só `primary` (CNPJ).
- Reanálise = gerar nova rodada e novas cotações mantendo histórico.

## Regras de Negócio
- Vigência: 30 meses sem overflow. Parcelas: 29 (sem entrada) ou 30 (com entrada).
- Comissão padrão: 15% (0.15 Pottencial; 15 pts Too/Tokio/Porto). Adicionais = 0.
- Pagamento: Pottencial (Invoice se cadastrada, senão Boleto); Too (`tipoPagamento=2` Fatura se cadastrada, senão `1` Boleto); Tokio (Faturado com código parceiro, senão CC); Porto (FAT com `porto_partner_code`, senão CC).
- Form Locatário: só valor do aluguel. Demais forms: despesas preenchidas + pintura/danos/multa (se água/luz removidos, persistir 0).
- Catálogo CNAE/Atividades: busca apenas por descrição, nunca por código.

## Regras por Seguradora
- **Pottencial:** Rota única (`participants[].documentNumber`), 3 planos (`Basic`, `Complete`, `Traditional`).
- **Too:** Status 8 e 16 liberam cotação; 5 = análise. Limite `TOO_STATUS_CHECK_MAX_PENDING_CHECKS` -> `awaiting_provider` (nunca recusa).
- **Tokio:** Apenas PF via API; PJ -> `manual_required`. `cdSitucCotac = CAL` aprova; resto recusa.
- **Porto (`PORTO_ENABLED`):** Ver detalhes técnicos em `docs/porto-seguro.md` antes de editar `Porto*`.

## Cuidados
- Sanitizar payloads/tokens/logs via `InsuranceDiagnosticSanitizer`.
- Manter `QUEUE_CONNECTION=database` transacional.
- Escopo cirúrgico: altere e teste apenas os arquivos da seguradora alvo.