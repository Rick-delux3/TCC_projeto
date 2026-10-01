<?php

return [
    'own_pdf_enabled' => (bool) env('ANALYSIS_OWN_PDF_ENABLED', false),
    'max_bytes' => 10 * 1024 * 1024,
    'download_timeout' => 25,
];
