<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ElectronicDocumentType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'customer_id', 'sold_at', 'total', 'payment_method', 'amount_received',
        'change', 'document_type', 'status', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $appends = ['code'];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
            'total' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'change' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'document_type' => DocumentType::class,
            'status' => SaleStatus::class,
        ];
    }

    /** Número interno de la venta, p. ej. V-000123. */
    protected function code(): Attribute
    {
        return Attribute::get(fn () => 'V-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ElectronicDocument::class);
    }

    /** Boleta o factura de la venta (sin contar notas de crédito). */
    public function invoice(): HasOne
    {
        // orderByDesc en lugar de latestOfMany: permite eager loading con columnas seleccionadas.
        return $this->hasOne(ElectronicDocument::class)
            ->whereIn('type', [ElectronicDocumentType::Boleta->value, ElectronicDocumentType::Factura->value])
            ->where('status', '!=', DocumentStatus::Anulado->value)
            ->orderByDesc('id');
    }

    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', SaleStatus::Completada->value);
    }

    public function isVoided(): bool
    {
        return $this->status === SaleStatus::Anulada;
    }
}
