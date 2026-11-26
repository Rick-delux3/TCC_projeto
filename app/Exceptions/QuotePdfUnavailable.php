<?php

namespace App\Exceptions;

/**
 * O PDF da cotação não pode ser baixado pela página: companhia não aprovada,
 * cotação fora da rodada atual, companhia sem PDF disponível ou cotação expirada.
 */
class QuotePdfUnavailable extends \DomainException {}
