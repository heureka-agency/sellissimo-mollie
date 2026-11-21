<?php

namespace Sellissimo\Mollie;

use Sellissimo\Core\Amendments;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Money;
use Sellissimo\Core\Refunds;
use Sellissimo\Core\Store;

class PaymentEvents
{
    public function __construct(private Store $store, private Gateway $gateway) {}

    public function order(string $orderId): void
    {
        $order = $this->store->table('orders')->where('id', $orderId)->firstOrFail();
        $captures = $this->store->table('amendments')->where('order_id', $orderId)->where('gateway', 'mollie')
            ->where('status', 'applied')->where('difference', '>', 0)->whereNotNull('payment_ref')->pluck('payment_ref');
        if ($order->gateway === 'mollie' && $order->payment_ref) {
            $captures->push($order->payment_ref);
        }
        throw_if($captures->isEmpty(), 'This order has no captured Mollie payment.');
        foreach ($captures->unique() as $capture) {
            $this->payment($capture);
        }
    }

    public function payment(string $paymentId, bool $callback = false): void
    {
        $order = $this->store->table('orders')->where('gateway', 'mollie')->where('session_id', $paymentId)->first();
        $captureId = null;
        if ($order) {
            $order = app(Checkout::class)->reconcile($order->id);
            if ($order->payment_ref !== $paymentId) {
                return;
            }
        } else {
            $change = $this->store->table('amendments')->where('gateway', 'mollie')->where('session_id', $paymentId)->first();
            if (!$change) {
                return;
            }
            $change = app(Amendments::class)->reconcile($change->id);
            if ($change->status !== 'applied' || $change->payment_ref !== $paymentId || $change->difference <= 0) {
                return;
            }
            $order = $this->store->table('orders')->where('id', $change->order_id)->firstOrFail();
            $captureId = $change->id;
        }

        $gateway = $this->gateway->forPayment($change ?? $order);
        foreach ($gateway->records("payments/$paymentId/refunds", 'refunds') as $refund) {
            $amount = $this->amount($refund, $paymentId, $order->currency, 're_');
            $localId = $refund['metadata']['refund'] ?? null;
            app(Refunds::class)->syncProviderRefund($order, $captureId, $refund['id'], is_string($localId) ? $localId : null,
                $amount, Gateway::refundStatus($refund['status']));
        }
        foreach ($gateway->records("payments/$paymentId/chargebacks", 'chargebacks') as $chargeback) {
            $amount = $this->amount($chargeback, $paymentId, $order->currency, 'chb_');
            $this->store->db()->transaction(function () use ($order, $captureId, $chargeback, $amount) {
                $this->store->table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $disputes = $this->store->table('disputes');
                $previous = (clone $disputes)->where('id', $chargeback['id'])->first();
                throw_if($previous && ($previous->order_id !== $order->id || $previous->capture_id !== $captureId
                    || $previous->currency !== $order->currency || (int) $previous->amount !== $amount), 'Chargeback does not match the captured payment.');
                $reversed = filled($chargeback['reversedAt'] ?? null);
                $movements = app(\Sellissimo\Core\DisputeMovements::class);
                $reference = 'mollie:'.$chargeback['id'];
                $at = filled($chargeback['createdAt'] ?? null) ? \Carbon\CarbonImmutable::parse($chargeback['createdAt']) : null;
                $movements->record($reference.':debit', $chargeback['id'], $order->id, $order->currency, -$amount, 0, $at);
                if ($reversed) {
                    $movements->record($reference.':credit', $chargeback['id'], $order->id, $order->currency, $amount, 0, \Carbon\CarbonImmutable::parse($chargeback['reversedAt']));
                }
                if ($previous?->status === 'chargeback_reversed' && !$reversed) {
                    return;
                }
                $status = $reversed ? 'chargeback_reversed' : 'charged_back';
                $disputes->updateOrInsert(['id' => $chargeback['id']], ['order_id' => $order->id, 'capture_id' => $captureId,
                    'amount' => $amount, 'currency' => $order->currency, 'status' => $status, 'cash_checked_at' => now(),
                    'reason' => $chargeback['reason']['description'] ?? $chargeback['reason']['code'] ?? null]);
                if ($previous?->status !== $status) {
                    $message = $reversed ? 'Mollie chargeback reversed' : 'Mollie chargeback received';
                    $this->store->recordActivity($order->id, 'dispute', "$message · ".$chargeback['id'].' · '.Money::display($amount, $order->currency).'.');
                }
            }, 3);
        }
        if ($callback) {
            $settings = app(\Sellissimo\Core\PaymentSettings::class);
            $settings->callback(($change ?? $order)->payment_connection_id ?? $settings->deploymentId('mollie'));
        }
    }

    private function amount(array $record, string $paymentId, string $currency, string $prefix): int
    {
        throw_unless(str_starts_with($record['id'] ?? '', $prefix) && ($record['paymentId'] ?? null) === $paymentId
            && ($record['amount']['currency'] ?? null) === $currency, 'Mollie record does not match the captured payment.');
        $amount = Money::parseDecimal($record['amount']['value'], Money::fractionDigits($currency), 'amount');
        throw_unless($amount > 0, 'Expected a positive payment amount.');

        return $amount;
    }
}
