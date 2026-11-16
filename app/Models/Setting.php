<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila clave/valor. Usar siempre App\Support\Settings para leer/escribir
 * (maneja caché y cifrado de credenciales).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];
}
