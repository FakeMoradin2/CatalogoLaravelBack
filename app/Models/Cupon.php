<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Cupon extends Model
{
    protected $table = 'cupones';

    protected $fillable = [
        'codigo',
        'tipo',
        'valor',
        'activo',
        'caduca_en',
        'max_usos',
        'usos_actuales',
    ];

    protected $casts = [
        'valor' => 'float',
        'activo' => 'boolean',
        'caduca_en' => 'datetime',
        'max_usos' => 'integer',
        'usos_actuales' => 'integer',
    ];

    public static function normalizarCodigo(?string $codigo): string
    {
        return strtoupper(trim((string) $codigo));
    }

    public function estaVigente(?Carbon $ahora = null): bool
    {
        $ahora ??= now();

        if ($this->caduca_en === null) {
            return true;
        }

        return $this->caduca_en->greaterThan($ahora);
    }

    public function tieneUsosDisponibles(): bool
    {
        if ($this->max_usos === null) {
            return true;
        }

        return $this->usos_actuales < $this->max_usos;
    }

    public function esAplicable(): bool
    {
        return $this->activo && $this->estaVigente() && $this->tieneUsosDisponibles();
    }

    public function calcularDescuento(float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        if ($this->tipo === 'porcentaje') {
            $pct = max(0.0, min(100.0, (float) $this->valor));

            return round(min($subtotal, $subtotal * ($pct / 100)), 2);
        }

        if ($this->tipo === 'fijo') {
            $fijo = max(0.0, (float) $this->valor);

            return round(min($subtotal, $fijo), 2);
        }

        return 0.0;
    }
}
