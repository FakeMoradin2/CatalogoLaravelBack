<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class PedidoPaymentController extends Controller
{
    public function prepare(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $clientId = $this->validatedClientId($request);

        if ($clientId !== $user->id) {
            return response()->json(['message' => 'No puedes iniciar pagos de otro cliente.'], 403);
        }

        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            return response()->json(['message' => 'Pasarela de pago no configurada en el servidor.'], 503);
        }

        $pedido = Pedido::query()
            ->where('id', $id)
            ->where('user_id', $clientId)
            ->first();

        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        if ($pedido->estado === 'cancelado') {
            return response()->json(['message' => 'No se puede pagar un pedido cancelado.'], 422);
        }

        if ($pedido->estado === 'pagado' || $pedido->payment_status === 'pagado') {
            return response()->json(['message' => 'Este pedido ya está pagado.'], 422);
        }

        $total = (float) $pedido->total;
        if ($total <= 0) {
            return response()->json(['message' => 'El monto del pedido no es válido para cobrar.'], 422);
        }

        $currency = strtolower((string) config('services.stripe.currency', 'usd'));
        $amountMinor = (int) round($total * 100);

        $publishable = (string) config('services.stripe.key');
        if ($publishable === '') {
            return response()->json([
                'message' => 'Falta la clave pública de Stripe (STRIPE_KEY o STRIPE_PUBLISHABLE_KEY en .env).',
            ], 503);
        }

        $minMinor = $this->minimumChargeMinorUnits($currency);
        if ($amountMinor < $minMinor) {
            return response()->json([
                'message' => $this->minimumChargeMessage($currency, $minMinor),
                'detail' => sprintf('Monto enviado: %d unidades mínimas; mínimo requerido: %d.', $amountMinor, $minMinor),
            ], 422);
        }

        try {
            $stripe = new StripeClient($secret);
            $intent = $stripe->paymentIntents->create([
                'amount' => $amountMinor,
                'currency' => $currency,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                'metadata' => [
                    'pedido_id' => (string) $pedido->id,
                    'user_id' => (string) $user->id,
                    'pedido_numero' => (string) $pedido->numero,
                ],
            ]);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'No se pudo iniciar el pago con la pasarela.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'client_secret' => $intent->client_secret,
            'payment_intent_id' => $intent->id,
            'publishable_key' => $publishable,
            'amount' => $total,
            'currency' => $currency,
        ]);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $clientId = $this->validatedClientId($request);

        if ($clientId !== $user->id) {
            return response()->json(['message' => 'No puedes confirmar pagos de otro cliente.'], 403);
        }

        $data = $request->validate([
            'payment_intent_id' => 'required|string',
        ]);

        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            return response()->json(['message' => 'Pasarela de pago no configurada en el servidor.'], 503);
        }

        $pedido = Pedido::query()
            ->where('id', $id)
            ->where('user_id', $clientId)
            ->first();

        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        if ($pedido->estado === 'cancelado') {
            return response()->json(['message' => 'El pedido está cancelado.'], 422);
        }

        if ($pedido->estado === 'pagado' || $pedido->payment_status === 'pagado') {
            $pedido->load('items');

            return response()->json([
                'message' => 'El pedido ya estaba registrado como pagado.',
                'order' => $pedido->toApiDetailArray(),
            ]);
        }

        try {
            $stripe = new StripeClient($secret);
            $intent = $stripe->paymentIntents->retrieve($data['payment_intent_id']);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'No se pudo verificar el pago con la pasarela.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        $metaPedido = $intent->metadata['pedido_id'] ?? null;
        if ((string) $metaPedido !== (string) $pedido->id) {
            return response()->json(['message' => 'El pago no corresponde a este pedido.'], 422);
        }

        $currency = strtolower((string) config('services.stripe.currency', 'usd'));
        $expectedAmount = (int) round((float) $pedido->total * 100);
        if ((int) $intent->amount !== $expectedAmount || strtolower((string) $intent->currency) !== $currency) {
            return response()->json(['message' => 'El monto del pago no coincide con el pedido.'], 422);
        }

        if ($intent->status !== 'succeeded') {
            return response()->json([
                'message' => 'El pago aún no se completó en la pasarela.',
                'payment_status' => $intent->status,
            ], 422);
        }

        $pedido->transaccion_id = $intent->id;
        $pedido->payment_status = 'pagado';
        $pedido->paid_at = now();
        $pedido->estado = 'pagado';
        $pedido->save();

        $pedido->refresh()->load('items');

        return response()->json([
            'message' => 'Pago registrado correctamente.',
            'order' => $pedido->toApiDetailArray(),
        ]);
    }

    /**
     * Crea una sesión de Stripe Checkout (pantalla alojada por Stripe).
     * Permite cupones/códigos promocionales creados en el Dashboard de Stripe (allow_promotion_codes).
     */
    public function checkoutSession(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
            'success_url' => 'required|string|max:2048',
            'cancel_url' => 'required|string|max:2048',
        ]);

        if ((int) $data['client_id'] !== $user->id) {
            return response()->json(['message' => 'No puedes iniciar pagos de otro cliente.'], 403);
        }

        $clientId = (int) $data['client_id'];

        if (!str_contains($data['success_url'], '{CHECKOUT_SESSION_ID}')) {
            return response()->json([
                'message' => 'success_url debe incluir el placeholder {CHECKOUT_SESSION_ID} (requerido por Stripe).',
            ], 422);
        }

        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            return response()->json(['message' => 'Pasarela de pago no configurada en el servidor.'], 503);
        }

        $pedido = Pedido::query()
            ->where('id', $id)
            ->where('user_id', $clientId)
            ->first();

        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        if ($pedido->estado === 'cancelado') {
            return response()->json(['message' => 'No se puede pagar un pedido cancelado.'], 422);
        }

        if ($pedido->estado === 'pagado' || $pedido->payment_status === 'pagado') {
            return response()->json(['message' => 'Este pedido ya está pagado.'], 422);
        }

        $total = (float) $pedido->total;
        if ($total <= 0) {
            return response()->json(['message' => 'El monto del pedido no es válido para cobrar.'], 422);
        }

        $currency = strtolower((string) config('services.stripe.currency', 'usd'));
        $amountMinor = (int) round($total * 100);

        $minMinor = $this->minimumChargeMinorUnits($currency);
        if ($amountMinor < $minMinor) {
            return response()->json([
                'message' => $this->minimumChargeMessage($currency, $minMinor),
                'detail' => sprintf('Monto enviado: %d unidades mínimas; mínimo requerido: %d.', $amountMinor, $minMinor),
            ], 422);
        }

        $allowPromo = filter_var(config('services.stripe.checkout_allow_promotion_codes', true), FILTER_VALIDATE_BOOLEAN);

        try {
            $stripe = new StripeClient($secret);
            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'locale' => config('services.stripe.checkout_locale', 'es'),
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => $currency,
                            'unit_amount' => $amountMinor,
                            'product_data' => [
                                'name' => 'Pedido '.$pedido->numero,
                                'description' => 'Pago de pedido en Mi Catálogo',
                            ],
                        ],
                        'quantity' => 1,
                    ],
                ],
                'success_url' => $data['success_url'],
                'cancel_url' => $data['cancel_url'],
                'allow_promotion_codes' => $allowPromo,
                'client_reference_id' => (string) $pedido->id,
                'metadata' => [
                    'pedido_id' => (string) $pedido->id,
                    'user_id' => (string) $user->id,
                    'pedido_numero' => (string) $pedido->numero,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'pedido_id' => (string) $pedido->id,
                        'user_id' => (string) $user->id,
                        'pedido_numero' => (string) $pedido->numero,
                    ],
                ],
            ]);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'No se pudo crear la sesión de pago en Stripe.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'url' => $session->url,
            'session_id' => $session->id,
        ]);
    }

    /**
     * Tras volver de Stripe Checkout, valida la sesión y marca el pedido pagado.
     * Acepta montos inferiores al total del pedido si el cliente usó un cupón de Stripe.
     */
    public function checkoutVerify(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
            'session_id' => 'required|string|max:255',
        ]);

        if ((int) $data['client_id'] !== $user->id) {
            return response()->json(['message' => 'No puedes confirmar pagos de otro cliente.'], 403);
        }

        $clientId = (int) $data['client_id'];

        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            return response()->json(['message' => 'Pasarela de pago no configurada en el servidor.'], 503);
        }

        $pedido = Pedido::query()
            ->where('id', $id)
            ->where('user_id', $clientId)
            ->first();

        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        if ($pedido->estado === 'cancelado') {
            return response()->json(['message' => 'El pedido está cancelado.'], 422);
        }

        if ($pedido->estado === 'pagado' || $pedido->payment_status === 'pagado') {
            $pedido->load('items');

            return response()->json([
                'message' => 'El pedido ya estaba registrado como pagado.',
                'order' => $pedido->toApiDetailArray(),
            ]);
        }

        $stripe = new StripeClient($secret);

        try {
            // Sin expand: con expand, algunas respuestas dejan payment_intent en null en el SDK aunque exista pi_… en JSON.
            $session = $stripe->checkout->sessions->retrieve($data['session_id'], []);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'No se pudo verificar la sesión de pago en Stripe.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        if ((string) ($session->metadata['pedido_id'] ?? '') !== (string) $pedido->id) {
            return response()->json(['message' => 'La sesión de pago no corresponde a este pedido.'], 422);
        }

        $paidStatuses = ['paid', 'no_payment_required'];
        if (!in_array($session->payment_status, $paidStatuses, true)) {
            return response()->json([
                'message' => 'El pago no se completó en Stripe.',
                'payment_status' => $session->payment_status,
            ], 422);
        }

        $currency = strtolower((string) config('services.stripe.currency', 'usd'));
        if (strtolower((string) $session->currency) !== $currency) {
            return response()->json(['message' => 'La moneda del cobro no coincide con la configuración.'], 422);
        }

        $paidMinor = (int) $session->amount_total;
        $expectedMax = (int) round((float) $pedido->total * 100);
        if ($paidMinor > $expectedMax) {
            return response()->json(['message' => 'El monto cobrado es mayor que el total del pedido.'], 422);
        }

        // No aplicar aquí minimumChargeMinorUnits: al crear la sesión ya validamos el total del pedido.
        // Con cupones de Stripe el cobro final puede ser menor que ese mínimo y Stripe igual completa el pago;
        // si payment_status === 'paid', confiamos en el monto que Stripe cobró.

        $piId = $this->resolveCheckoutSessionPaymentIntentId($stripe, $session);

        if ($piId === null) {
            $piId = $this->findPaymentIntentIdViaMetadataSearch($stripe, $pedido, $session);
        }

        if ($piId === null && $session->payment_status === 'no_payment_required' && (int) $session->amount_total === 0) {
            $pedido->transaccion_id = $session->id;

            $pedido->payment_status = 'pagado';
            $pedido->paid_at = now();
            $pedido->estado = 'pagado';
            $pedido->save();

            $pedido->refresh()->load('items');

            return response()->json([
                'message' => 'Pago registrado correctamente.',
                'order' => $pedido->toApiDetailArray(),
            ]);
        }

        if ($piId === null && $session->payment_status === 'paid') {
            // Stripe marcó el cobro como pagado pero a veces no expone payment_intent en la sesión (API/cuenta).
            // El id de sesión (cs_…) es válido para localizar el pago en el Dashboard.
            $pedido->transaccion_id = $session->id;
            $pedido->payment_status = 'pagado';
            $pedido->paid_at = now();
            $pedido->estado = 'pagado';
            $pedido->save();

            $pedido->refresh()->load('items');

            return response()->json([
                'message' => 'Pago registrado correctamente.',
                'order' => $pedido->toApiDetailArray(),
            ]);
        }

        if ($piId === null) {
            return response()->json([
                'message' => 'No se encontró el PaymentIntent en la sesión.',
                'detail' => 'Vuelve a intentar el pago; si el cargo ya aparece en Stripe, conserva el enlace de regreso con session_id.',
            ], 422);
        }

        try {
            $pi = $stripe->paymentIntents->retrieve($piId);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'No se pudo leer el PaymentIntent en Stripe.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        if ($pi->status !== 'succeeded') {
            return response()->json([
                'message' => 'El pago aún no se completó en la pasarela.',
                'payment_status' => $pi->status,
            ], 422);
        }

        $pedido->transaccion_id = $pi->id;
        $pedido->payment_status = 'pagado';
        $pedido->paid_at = now();
        $pedido->estado = 'pagado';
        $pedido->save();

        $pedido->refresh()->load('items');

        return response()->json([
            'message' => 'Pago registrado correctamente.',
            'order' => $pedido->toApiDetailArray(),
        ]);
    }

    /**
     * Resuelve el id del PaymentIntent (pi_…) a partir de la Checkout Session.
     * Cubre string, objeto, ArrayAccess, toArray(), segunda petición con expand e invoice.
     */
    private function resolveCheckoutSessionPaymentIntentId(StripeClient $stripe, \Stripe\Checkout\Session $session): ?string
    {
        $candidates = [$session];
        try {
            $candidates[] = $stripe->checkout->sessions->retrieve($session->id, [
                'expand' => ['payment_intent'],
            ]);
        } catch (ApiErrorException) {
            // ignorar: ya tenemos la sesión sin expand
        }

        foreach ($candidates as $s) {
            $id = $this->extractPaymentIntentIdFromSessionSnapshot($s);
            if ($id !== null) {
                return $id;
            }
        }

        return $this->resolvePaymentIntentIdFromSessionInvoice($stripe, $session);
    }

    private function extractPaymentIntentIdFromSessionSnapshot(\Stripe\Checkout\Session $session): ?string
    {
        $refs = [
            $session->payment_intent,
            $session['payment_intent'] ?? null,
        ];

        foreach ($refs as $ref) {
            if (is_string($ref) && str_starts_with($ref, 'pi_')) {
                return $ref;
            }
            if (is_object($ref) && isset($ref->id) && is_string($ref->id) && str_starts_with($ref->id, 'pi_')) {
                return $ref->id;
            }
        }

        $raw = $session->toArray();
        $piField = $raw['payment_intent'] ?? null;
        if (is_string($piField) && str_starts_with($piField, 'pi_')) {
            return $piField;
        }
        if (is_array($piField) && isset($piField['id']) && is_string($piField['id']) && str_starts_with($piField['id'], 'pi_')) {
            return $piField['id'];
        }

        return null;
    }

    private function resolvePaymentIntentIdFromSessionInvoice(StripeClient $stripe, \Stripe\Checkout\Session $session): ?string
    {
        $invRef = $session->invoice ?? null;
        if ($invRef === null || $invRef === '') {
            return null;
        }

        $invId = is_string($invRef) ? $invRef : (is_object($invRef) && isset($invRef->id) ? (string) $invRef->id : null);
        if ($invId === null || $invId === '' || !str_starts_with($invId, 'in_')) {
            return null;
        }

        try {
            $invoice = $stripe->invoices->retrieve($invId, []);
        } catch (ApiErrorException) {
            return null;
        }

        $invPi = $invoice->payment_intent ?? null;
        if (is_string($invPi) && str_starts_with($invPi, 'pi_')) {
            return $invPi;
        }
        if (is_object($invPi) && isset($invPi->id) && is_string($invPi->id) && str_starts_with($invPi->id, 'pi_')) {
            return $invPi->id;
        }

        return null;
    }

    /**
     * Último recurso antes del fallback con id de sesión: PI con metadata pedido_id y mismo monto/moneda que la Checkout Session.
     */
    private function findPaymentIntentIdViaMetadataSearch(StripeClient $stripe, Pedido $pedido, \Stripe\Checkout\Session $session): ?string
    {
        try {
            // Sintaxis Stripe: metadata['clave']:'valor' (la búsqueda puede tardar ~1 min en indexar).
            $pid = (int) $pedido->id;
            $result = $stripe->paymentIntents->search([
                'query' => "metadata['pedido_id']:'{$pid}' AND status:'succeeded'",
                'limit' => 10,
            ]);
        } catch (ApiErrorException) {
            return null;
        }

        $expectedMinor = (int) $session->amount_total;
        $currency = strtolower((string) $session->currency);
        $bestId = null;
        $bestCreated = -1;

        foreach ($result->data as $pi) {
            if ((int) $pi->amount !== $expectedMinor) {
                continue;
            }
            if (strtolower((string) $pi->currency) !== $currency) {
                continue;
            }
            $created = (int) ($pi->created ?? 0);
            if ($created >= $bestCreated) {
                $bestCreated = $created;
                $bestId = $pi->id;
            }
        }

        return is_string($bestId) && str_starts_with($bestId, 'pi_') ? $bestId : null;
    }

    private function validatedClientId(Request $request): int
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
        ]);

        return (int) $validated['client_id'];
    }

    /**
     * Unidades mínimas de cobro por moneda (Stripe).
     * MXN: centavos; el mínimo habitual es $10.00 MXN (1000).
     * USD: centavos; mínimo habitual $0.50 USD (50).
     *
     * @see https://docs.stripe.com/currencies
     */
    private function minimumChargeMinorUnits(string $currency): int
    {
        return match ($currency) {
            'mxn' => 1000,
            'usd' => 50,
            'eur' => 50,
            default => 50,
        };
    }

    private function minimumChargeMessage(string $currency, int $minMinor): string
    {
        $major = $minMinor / 100;

        return match ($currency) {
            'mxn' => sprintf('El monto mínimo para cobrar en MXN con Stripe es $%.2f MXN. Aumenta el pedido o usa otra moneda en STRIPE_CURRENCY.', $major),
            'usd' => sprintf('El monto mínimo para cobrar en USD con Stripe es $%.2f USD.', $major),
            default => sprintf('El monto es inferior al mínimo permitido para la moneda %s.', $currency),
        };
    }
}
