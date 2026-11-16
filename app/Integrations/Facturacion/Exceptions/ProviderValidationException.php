<?php

namespace App\Integrations\Facturacion\Exceptions;

/** El proveedor rechazó la petición por datos inválidos (4xx). No se reintenta. */
class ProviderValidationException extends FacturacionException {}
