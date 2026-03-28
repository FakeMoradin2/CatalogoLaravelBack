<?php

namespace App\Support;

use App\Models\Producto;

class PedidoSubtotal
{
    /**
     * @param  array<int, array{id: int, quantity: int}>  $items
     */
    public static function fromLineItems(array $items): float
    {
        $total = 0.0;

        foreach ($items as $rawItem) {
            $producto = Producto::query()->where('id', (int) $rawItem['id'])->first();

            if (!$producto) {
                throw new \RuntimeException('Producto no encontrado.');
            }

            $quantity = (int) $rawItem['quantity'];
            $total += (float) $producto->price * $quantity;
        }

        return $total;
    }
}
