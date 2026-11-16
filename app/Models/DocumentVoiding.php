<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comunicación de baja (RA) o resumen de anulación (RC) de un comprobante. */
class DocumentVoiding extends Model
{
    protected $fillable = [
        'electronic_document_id', 'user_id', 'kind', 'reason', 'provider_id', 'identifier', 'ticket',
        'status', 'sunat_code', 'sunat_message', 'request_payload', 'response_payload', 'last_checked_at',
    ];

    protected $hidden = ['request_payload', 'response_payload'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }

    public function kindLabel(): string
    {
        return $this->kind === 'RC' ? 'Resumen de anulación' : 'Comunicación de baja';
    }

    /** En curso o aceptada: no se puede pedir otra. */
    public function isActive(): bool
    {
        return in_array($this->status, [DocumentStatus::Pendiente, DocumentStatus::Enviado, DocumentStatus::Aceptado], true);
    }
}
