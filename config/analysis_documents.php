<?php

return [
    'own_pdf_enabled' => (bool) env('ANALYSIS_OWN_PDF_ENABLED', false),
    'max_bytes' => 10 * 1024 * 1024,
    'download_timeout' => 25,
    // A Pottencial levou de 21 s a 45 s para gerar o PDF da cotação em homologação (26/11/2026).
    'pottencial_quote_pdf_timeout' => 60,
    // Validade do PDF da cotação na página; a Tokio usa o dtVldad devolvido na cotação.
    'quote_validity_months' => 30,
];
