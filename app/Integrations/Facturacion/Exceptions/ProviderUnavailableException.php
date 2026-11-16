<?php

namespace App\Integrations\Facturacion\Exceptions;

/** Timeout, error de red, 429 o 5xx: se puede reintentar. */
class ProviderUnavailableException extends FacturacionException {}
