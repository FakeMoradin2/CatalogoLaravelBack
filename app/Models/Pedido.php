<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $fillable = [
        'numero',
        'user_id',
        'estado',
        'total',
        'cupon_codigo',
        'descuento',
        'transaccion_id',
        'payment_status',
        'paid_at',
    ];

    protected $casts = [
        'total' => 'float',
        'descuento' => 'float',
        'paid_at' => 'datetime',
    ];

    public function toApiListArray(): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'fecha' => optional($this->created_at)?->toDateTimeString(),
            'estado' => $this->estado,
            'subtotal' => $this->subtotalItems(),
            'descuento' => (float) ($this->descuento ?? 0),
            'cupon_codigo' => $this->cupon_codigo,
            'total' => (float) $this->total,
            'transaccion_id' => $this->transaccion_id,
            'payment_status' => $this->payment_status,
            'fecha_pago' => optional($this->paid_at)?->toDateTimeString(),
        ];
    }

    public function toApiDetailArray(): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'fecha' => optional($this->created_at)?->toDateTimeString(),
            'estado' => $this->estado,
            'subtotal' => $this->subtotalItems(),
            'descuento' => (float) ($this->descuento ?? 0),
            'cupon_codigo' => $this->cupon_codigo,
            'total' => (float) $this->total,
            'transaccion_id' => $this->transaccion_id,
            'payment_status' => $this->payment_status,
            'fecha_pago' => optional($this->paid_at)?->toDateTimeString(),
            'items' => $this->items->map(function ($item): array {
                return [
                    'id' => $item->id,
                    'producto_id' => $item->producto_id,
                    'title' => $item->title,
                    'price' => (float) $item->price,
                    'quantity' => (int) $item->quantity,
                    'subtotal' => (float) $item->subtotal,
                ];
            })->values()->all(),
        ];
    }

    public function subtotalItems(): float
    {
        return round((float) $this->items->sum(fn ($item) => (float) $item->subtotal), 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }
}
