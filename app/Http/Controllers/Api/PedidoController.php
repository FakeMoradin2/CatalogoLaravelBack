<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cupon;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PedidoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $clientId = $this->validatedClientId($request);

        if ($clientId !== $user->id) {
            return response()->json(['message' => 'No puedes consultar pedidos de otro cliente.'], 403);
        }

        $pedidos = Pedido::query()
            ->with('items')
            ->where('user_id', $clientId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Pedido $pedido) => $pedido->toApiListArray());

        return response()->json([
            'orders' => $pedidos,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $clientId = $this->validatedClientId($request);

        if ($clientId !== $user->id) {
            return response()->json(['message' => 'No puedes consultar pedidos de otro cliente.'], 403);
        }

        $pedido = Pedido::with('items')
            ->where('id', $id)
            ->where('user_id', $clientId)
            ->first();

        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        return response()->json([
            'order' => $pedido->toApiDetailArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
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
            return response()->json(['message' => 'No puedes crear pedidos para otro cliente.'], 403);
        }

        try {
            $pedido = DB::transaction(function () use ($data, $user): Pedido {
                $orderNumber = 'PED-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
                $pedido = Pedido::create([
                    'numero' => $orderNumber,
                    'user_id' => $user->id,
                    'estado' => 'creado',
                    'total' => 0,
                    'payment_status' => 'pendiente',
                    'descuento' => 0,
                ]);

                $subtotalPedido = 0.0;

                foreach ($data['items'] as $rawItem) {
                    $producto = Producto::query()
                        ->where('id', (int) $rawItem['id'])
                        ->lockForUpdate()
                        ->first();

                    $quantity = (int) $rawItem['quantity'];

                    if (!$producto) {
                        throw new \RuntimeException('Producto no encontrado durante el proceso de pedido.');
                    }

                    if ($producto->stock < $quantity) {
                        throw new \RuntimeException("Stock insuficiente para el producto {$producto->title}.");
                    }

                    $producto->stock -= $quantity;
                    $producto->save();

                    $lineSubtotal = (float) $producto->price * $quantity;
                    $subtotalPedido += $lineSubtotal;

                    $pedido->items()->create([
                        'producto_id' => $producto->id,
                        'title' => $producto->title,
                        'price' => $producto->price,
                        'quantity' => $quantity,
                        'subtotal' => $lineSubtotal,
                    ]);
                }

                $subtotalPedido = round($subtotalPedido, 2);

                $codigoCupon = Cupon::normalizarCodigo($data['coupon_code'] ?? '');
                $descuento = 0.0;
                $cuponAplicado = null;

                if ($codigoCupon !== '') {
                    $cupon = Cupon::query()
                        ->where('codigo', $codigoCupon)
                        ->lockForUpdate()
                        ->first();

                    if (!$cupon || !$cupon->esAplicable()) {
                        throw new \RuntimeException('El código de descuento no es válido o ya no está disponible.');
                    }

                    $descuento = $cupon->calcularDescuento((float) $subtotalPedido);
                    $cuponAplicado = $cupon->codigo;
                    $cupon->increment('usos_actuales');
                }

                $pedido->descuento = round($descuento, 2);
                $pedido->cupon_codigo = $cuponAplicado;
                $pedido->total = round(max(0.0, $subtotalPedido - $descuento), 2);
                $pedido->save();

                return $pedido->load('items');
            });
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Pedido creado correctamente.',
            'order' => $pedido->toApiDetailArray(),
        ], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $clientId = $this->validatedClientId($request);

        if ($clientId !== $user->id) {
            return response()->json(['message' => 'No puedes cancelar pedidos de otro cliente.'], 403);
        }

        $pedido = Pedido::with('items')
            ->where('id', $id)
            ->where('user_id', $clientId)
            ->first();

        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        if ($pedido->estado === 'cancelado') {
            return response()->json(['message' => 'El pedido ya está cancelado.'], 422);
        }

        if ($pedido->estado === 'pagado' || $pedido->payment_status === 'pagado') {
            return response()->json(['message' => 'No se puede cancelar un pedido ya pagado.'], 422);
        }

        DB::transaction(function () use ($pedido): void {
            foreach ($pedido->items as $item) {
                $producto = Producto::query()
                    ->where('id', $item->producto_id)
                    ->lockForUpdate()
                    ->first();

                if ($producto) {
                    $producto->stock += (int) $item->quantity;
                    $producto->save();
                }
            }

            $pedido->estado = 'cancelado';
            $pedido->save();
        });

        $pedido->refresh();
        $pedido->load('items');

        return response()->json([
            'message' => 'Pedido cancelado correctamente.',
            'order' => $pedido->toApiDetailArray(),
        ]);
    }

    private function validatedClientId(Request $request): int
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
        ]);

        return (int) $validated['client_id'];
    }
}
