<?php

namespace App\Integrations\Facturacion\DTO;

final class DownloadedFile
{
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly string $filename,
    ) {}
}
