<?php

namespace App\Exceptions;

/**
 * A carta de recusa não pode ser baixada pela página: companhia sem carta,
 * cotação fora da rodada atual ou pacote sem nenhuma aprovação (nesse caso
 * as cartas seguem por e-mail).
 */
class RefusalLetterUnavailable extends \DomainException {}
