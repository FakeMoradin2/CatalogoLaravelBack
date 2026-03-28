<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cupon;
use App\Models\User;
use App\Support\PedidoSubtotal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CuponController extends Controller
{
    public function validateCoupon(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:productos,id',
            'items.*.quantity' => 'required|integer|min:1',
            'coupon_code' => 'nullable|string|max:50',
        ]);

        if ((int) $data['client_id'] !== $user->id) {
            return response()->json(['message' => 'Operación no permitida.'], 403);
        }

        try {
            $subtotal = PedidoSubtotal::fromLineItems($data['items']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $codigo = Cupon::normalizarCodigo($data['coupon_code'] ?? '');
        if ($codigo === '') {
            return response()->json([
                'subtotal' => round($subtotal, 2),
                'descuento' => 0.0,
                'total' => round($subtotal, 2),
                'cupon_codigo' => null,
            ]);
        }

        $cupon = Cupon::query()->where('codigo', $codigo)->first();
        if (!$cupon || !$cupon->esAplicable()) {
            return response()->json(['message' => 'El código de descuento no es válido o ya no está disponible.'], 422);
        }

        $descuento = $cupon->calcularDescuento($subtotal);
        $total = round(max(0.0, $subtotal - $descuento), 2);

        return response()->json([
            'subtotal' => round($subtotal, 2),
            'descuento' => $descuento,
            'total' => $total,
            'cupon_codigo' => $cupon->codigo,
        ]);
    }
}
