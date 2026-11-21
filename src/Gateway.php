<?php

namespace Sellissimo\Mollie;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Contracts\DeterminesPaymentAvailability;
use Sellissimo\Core\Contracts\PaymentGateway;
use Sellissimo\Core\Contracts\ChecksPaymentConnection;
use Sellissimo\Core\Contracts\PaymentUnavailable;
use Sellissimo\Core\Contracts\PaymentRejected;
use Sellissimo\Core\Contracts\ReportsPaymentConfiguration;
use Sellissimo\Core\Money;
use Sellissimo\Core\PaymentSettings;
use Sellissimo\Core\Contracts\ReconcilesOrderPayments;

class Gateway implements PaymentGateway, ChecksPaymentConnection, ReconcilesOrderPayments, DeterminesPaymentAvailability, ReportsPaymentConfiguration
{
    public function __construct(private ?string $connection = null) {}

    public function forPayment(object $payment): self
    {
        return new self($payment->payment_connection_id ?? null);
    }

    public static function checkConnection(array $credentials): string
    {
        $result = Http::withToken($credentials['key'])->acceptJson()->timeout(10)->withoutRedirecting()
            ->get('https://api.mollie.com/v2/profiles/me');
        $id = $result->json('id');
        throw_unless($result->successful() && is_string($id) && str_starts_with($id, 'pfl_'), PaymentUnavailable::class, 'Mollie could not verify this profile.');
        return $id;
    }

    public function isAvailable(array $quote, string $site): bool
    {
        return isset(app(PaymentSettings::class)->forSite('mollie', $site)['key']);
    }

    public function configurationWarnings(string $site): array
    {
        return isset(app(PaymentSettings::class)->forSite('mollie', $site)['key']) ? [] : [__('sellissimo::shop.set_payment_setting', ['provider' => 'Mollie', 'setting' => 'SELLISSIMO_MOLLIE_KEY'])];
    }

    private function request(callable $operation): array
    {
        try {
            $result = $operation();
            throw_unless(is_array($result), PaymentUnavailable::class, 'Mollie returned an unreadable payment response.');
            return $result;
        } catch (ConnectionException|RequestException $error) {
            $status = $error instanceof RequestException ? $error->response->status() : 'connection error';
            throw new PaymentUnavailable("Mollie Checkout is unavailable ($status). Check the Mollie Dashboard.");
        }
    }

    private function api(): PendingRequest
    {
        $key = app(PaymentSettings::class)->credentials('mollie', $this->connection)['key'] ?? null;
        throw_if($key === null, PaymentUnavailable::class, 'Set SELLISSIMO_MOLLIE_KEY to resume Mollie payments.');

        return Http::baseUrl('https://api.mollie.com/v2')->withToken($key)->acceptJson()->timeout(15)->withoutRedirecting();
    }

    public function start(object $order): array
    {
        return $this->request(fn () => $this->forPayment($order)->startSession($order));
    }

    private function startSession(object $order): array
    {
        if ($order->session_id) {
            return ['id' => $order->session_id, 'url' => $order->checkout_url];
        }
        // Beyond Mollie's key retention window, recover the original payment or expire the cart.
        if (Carbon::parse($order->created_at)->lt(now()->subMinutes(50))) {
            $payment = $this->find('payments', 'payments', 'order', $order->id, $order->created_at);
            if (! $payment) {
                return ['status' => 'expired'];
            }
        } else {
            $response = $this->api()->withHeaders(['Idempotency-Key' => $order->id])->post('payments', [
                'amount' => $this->amount($order->total, $order->currency),
                'description' => 'Order '.$order->id, 'metadata' => ['order' => $order->id],
                'redirectUrl' => json_decode($order->receipt, true)['return_url'], 'webhookUrl' => route('sellissimo.mollie.webhook'),
            ]);
            $status = $response->status();
            if (in_array($status, [400, 401, 403, 422], true) && $response->json('status') === $status) {
                throw new PaymentRejected("Mollie refused the payment request (HTTP $status). Check the Mollie Dashboard.");
            }
            $payment = $response->throw()->json();
        }
        $this->verify($payment, $order);

        return ['id' => $payment['id'], 'url' => $payment['_links']['checkout']['href'] ?? Checkout::link($order)];
    }

    public function outcome(object $order): array
    {
        $payment = $this->request(fn () => $this->forPayment($order)->api()->get('payments/'.$order->session_id)->throw()->json());
        $this->verify($payment, $order);

        return match ($payment['status']) {
            'paid' => ['status' => 'paid', 'reference' => $payment['id'], 'amount' => (int) $order->total, 'currency' => $order->currency],
            'expired', 'canceled', 'failed' => ['status' => 'expired'],
            default => ['status' => 'pending'],
        };
    }

    private function amount(int $value, string $currency): array
    {
        return ['currency' => $currency, 'value' => Money::decimal($value, $currency)];
    }

    private function verify(mixed $payment, object $order): void
    {
        throw_unless(is_array($payment) && is_string($payment['id'] ?? null) && preg_match('/^tr_[a-zA-Z0-9]+$/D', $payment['id']) && (! $order->session_id || $payment['id'] === $order->session_id)
            && ($payment['metadata']['order'] ?? null) === $order->id && ($payment['amount'] ?? null) == $this->amount($order->total, $order->currency), PaymentUnavailable::class, 'Mollie payment does not match this order.');
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(object $order, object $refund): string
    {
        try {
            return $this->forPayment($order)->refundPayment($order, $refund);
        } catch (ConnectionException|RequestException $error) {
            $status = $error instanceof RequestException ? $error->response->status() : 'connection error';
            throw new PaymentUnavailable("Mollie refund is unavailable ($status). Check the Mollie Dashboard.");
        }
    }

    private function refundPayment(object $order, object $refund): string
    {
        $path = 'payments/'.$order->session_id.'/refunds';
        $amount = $this->amount($refund->amount, $order->currency);
        if ($refund->provider_ref ?? null) {
            $result = $this->api()->get($path.'/'.rawurlencode($refund->provider_ref))->throw()->json();
            throw_unless(($result['id'] ?? null) === $refund->provider_ref && ($result['paymentId'] ?? null) === $order->session_id
                && $result['amount'] == $amount, 'Mollie refund does not match the captured payment.');

            return self::refundStatus($result['status']);
        }
        $result = $this->find($path, 'refunds', 'refund', $refund->id);
        if (! $result) {
            $result = $this->api()->withHeaders(['Idempotency-Key' => $refund->id])->post($path, [
                'amount' => $amount, 'metadata' => ['refund' => $refund->id],
            ])->throw()->json();
        }
        throw_unless(($result['paymentId'] ?? null) === $order->session_id && ($result['metadata']['refund'] ?? null) === $refund->id && $result['amount'] == $amount, 'Mollie refund does not match this request.');

        return self::refundStatus($result['status']);
    }

    public function reconcileOrder(string $orderId): void
    {
        app(PaymentEvents::class)->order($orderId);
    }

    public static function refundStatus(string $status): string
    {
        return match ($status) {
            'refunded' => 'succeeded', 'failed', 'canceled' => 'failed', default => 'pending',
        };
    }

    private function find(string $path, string $type, string $metadataKey, string $id, ?string $since = null): ?array
    {
        foreach ($this->records($path, $type) as $item) {
            if (($item['metadata'][$metadataKey] ?? null) === $id) {
                return $item;
            }
            if ($since && Carbon::parse($item['createdAt'])->lt(Carbon::parse($since)->subMinutes(5))) {
                break;
            }
        }
        return null;
    }

    public function records(string $path, string $type): iterable
    {
        $params = ['limit' => 250];
        $seen = [];
        do {
            $page = $this->request(fn () => $this->api()->get($path, $params)->throw()->json());
            yield from $page['_embedded'][$type];
            $next = $page['_links']['next']['href'] ?? null;
            if ($next) {
                parse_str(parse_url($next, PHP_URL_QUERY) ?? '', $query);
                throw_unless(is_string($query['from'] ?? null) && $query['from'] !== '' && !isset($seen[$query['from']]), 'Invalid Mollie pagination cursor.');
                $params['from'] = $query['from'];
                $seen[$params['from']] = true;
            }
        } while ($next);
    }
}
