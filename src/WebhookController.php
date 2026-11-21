<?php

namespace Sellissimo\Mollie;

use Illuminate\Http\Request;

class WebhookController
{
    public function __invoke(Request $request, PaymentEvents $events)
    {
        $id = $request->validate(['id' => ['required', 'string', 'max:100', 'regex:/^tr_[A-Za-z0-9]+$/D']])['id'];
        $events->payment($id, true);

        return response()->noContent();
    }
}
