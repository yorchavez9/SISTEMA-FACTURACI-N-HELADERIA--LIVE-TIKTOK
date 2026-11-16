<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\ElectronicDocumentType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ElectronicDocument extends Model
{
    use HasFactory;

    /** Días que SUNAT permite para comunicación de baja / resumen de anulación. */
    public const VOID_DAYS = 7;

    protected $fillable = [
        'sale_id', 'type', 'series', 'number', 'provider_id', 'related_document_id', 'reason_code', 'reason',
        'customer_snapshot', 'total', 'status', 'sunat_code', 'sunat_message', 'hash',
        'request_payload', 'response_payload', 'attempts', 'issued_at', 'last_attempt_at', 'last_checked_at',
    ];

    protected $hidden = ['request_payload', 'response_payload'];

    protected $appends = ['full_number'];

    protected function casts(): array
    {
        return [
            'type' => ElectronicDocumentType::class,
            'status' => DocumentStatus::class,
            'customer_snapshot' => 'array',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /** B001-00000123 (o B001-???????? si aún no tiene número). */
    protected function fullNumber(): Attribute
    {
        return Attribute::get(fn () => $this->series.'-'.($this->number !== null
            ? str_pad((string) $this->number, 8, '0', STR_PAD_LEFT)
            : '--------'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** Para una nota: el comprobante que modifica. */
    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_document_id');
    }

    /** Para una boleta/factura: sus notas de crédito y débito. */
    public function notes(): HasMany
    {
        return $this->hasMany(self::class, 'related_document_id');
    }

    /** Última nota de crédito (compatibilidad con la vista de detalle). */
    public function creditNote(): HasOne
    {
        return $this->hasOne(self::class, 'related_document_id')
            ->where('type', ElectronicDocumentType::NotaCredito->value)
            ->orderByDesc('id');
    }

    public function voidings(): HasMany
    {
        return $this->hasMany(DocumentVoiding::class);
    }

    public function latestVoiding(): HasOne
    {
        return $this->hasOne(DocumentVoiding::class)->orderByDesc('id');
    }

    public function isRegisteredInProvider(): bool
    {
        return $this->provider_id !== null;
    }

    public function isBase(): bool
    {
        return in_array($this->type, [ElectronicDocumentType::Factura, ElectronicDocumentType::Boleta], true);
    }

    /** RA para facturas y notas de serie F; RC para boletas. Null si no aplica. */
    public function voidingKind(): ?string
    {
        return match (true) {
            $this->type === ElectronicDocumentType::Boleta => 'RC',
            $this->type === ElectronicDocumentType::Factura => 'RA',
            $this->type->isNote() && str_starts_with($this->series, 'F') => 'RA',
            default => null,
        };
    }

    /**
     * Acciones disponibles y, si no, el motivo (para mostrarlo en el menú).
     *
     * @return array{credit_note: true|string, debit_note: true|string, void: true|string}
     */
    public function availableActions(): array
    {
        $accepted = $this->status === DocumentStatus::Aceptado;
        $voiding = $this->relationLoaded('latestVoiding') ? $this->latestVoiding : $this->latestVoiding()->first();

        $note = match (true) {
            ! $this->isBase() => 'Solo para boletas y facturas',
            ! $accepted => 'El comprobante debe estar aceptado por SUNAT',
            $voiding?->isActive() => 'El comprobante tiene una baja en curso',
            default => true,
        };

        $void = match (true) {
            $this->status === DocumentStatus::Anulado => 'El comprobante ya está anulado',
            ! $accepted => 'El comprobante debe estar aceptado por SUNAT',
            $voiding?->isActive() => $voiding->kindLabel().' ya enviada',
            $this->voidingKind() === null => 'Las notas de boletas se anulan con otra nota',
            $this->issued_at->lt(now()->startOfDay()->subDays(self::VOID_DAYS)) => 'Fuera de plazo (7 días): use nota de crédito',
            default => true,
        };

        return ['credit_note' => $note, 'debit_note' => $note, 'void' => $void];
    }
}
