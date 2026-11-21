# Mollie for Sellissimo

## Licensing

Mollie for Sellissimo is a free extension for Sellissimo Core. Install it on any site covered by your Core license. Development and staging are free. Your payment provider charges its own processing fees. [License agreement](LICENSE) · [Site licensing guide](https://sellissimo.heureka-kreativ.hu/docs/licensing)

## Setup

Install the matching packages from your Statamic site:

```sh
composer require sellissimo/core sellissimo/mollie
php please sellissimo:install
```

Use your own Mollie account. Mollie charges its payment processing fees separately. [Mollie pricing](https://www.mollie.com/pricing)

Open **Store → Settings → Payments**. Under **Mollie → Manage shared credentials**, choose **Test** and enter a Mollie profile test key. **Verify and save credentials** reads the profile identity from Mollie. For the selected site, turn off inherited payment methods, enable Mollie, select the test connection and save. Place a test order in the storefront. The page shows the last verified callback and completed checkout separately. Use a publicly reachable HTTPS application URL for callbacks.

Connect your live profile when ready and select **Live** for the intended site. Each payment retains its original profile and mode for callbacks, reconciliation and refunds. A new key verified for the same profile and mode can service earlier payments. Verify a hosting-managed profile before revoking its key and keep access to profiles with outstanding refunds. Back up the commerce database and protect the Laravel encryption key needed to read the credentials.

To manage credentials in hosting, set `SELLISSIMO_MOLLIE_KEY` and choose **Hosting configuration**. Set `SELLISSIMO_MOLLIE_LOCK_CREDENTIALS=true` to enforce this source and prevent changes to shared Mollie credentials in the CP. Rebuild cached configuration after changing hosting values.

Checkout redirects the buyer to Mollie. The webhook at `/api/sellissimo/mollie/webhook` retrieves the authoritative payment and checks the order metadata, currency and amount. The scheduler also reconciles pending payments and refunds. Partial refunds use a unique request ID and search existing Mollie refunds before submission.

Verify your enabled payment methods with a test-mode key on Mollie’s hosted checkout before taking live payments. [Mollie payment creation](https://docs.mollie.com/reference/create-payment) · [Fulfillment callbacks](https://docs.mollie.com/docs/triggering-fulfilment)

After capture, each payment callback checks Mollie’s paginated refunds and chargebacks. This includes additional payments for order amendments. Refunds created in the Mollie Dashboard update the order’s payment history. A chargeback shows a dispatch warning until Mollie reports its reversal. Chargebacks are tracked separately from refunds.

Staff with refund permission can use **Refresh Mollie payments** on the order to recover a missed callback. From the command line, use `php artisan sellissimo:mollie:reconcile ORDER_UUID`. Both read the payment provider’s records and can be repeated. [Mollie refunds](https://docs.mollie.com/reference/list-refunds) · [Mollie chargebacks](https://docs.mollie.com/reference/list-payment-chargebacks)

For product support, email [the Sellissimo team](mailto:hello@heureka-kreativ.hu).
