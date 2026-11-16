<?php

namespace App\Models;

use App\Enums\IdentityDocType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['doc_type', 'doc_number', 'name', 'address', 'phone', 'email'];

    protected function casts(): array
    {
        return ['doc_type' => IdentityDocType::class];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** Datos del cliente tal como viajan en el comprobante. */
    public function toSnapshot(): array
    {
        return [
            'tipo_doc' => $this->doc_type->value,
            'num_doc' => $this->doc_number,
            'razon_social' => $this->name,
            'direccion' => $this->address,
        ];
    }
}
