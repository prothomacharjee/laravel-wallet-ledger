<?php

declare(strict_types=1);

return [

    /*
    | Shared secrets the fake payment gateways sign webhooks with (HMAC-SHA256).
    | An empty secret makes every webhook fail verification.
    */
    'fake-crypto' => [
        'webhook_secret' => env('FAKE_CRYPTO_WEBHOOK_SECRET', ''),
    ],

    'fake-fiat' => [
        'webhook_secret' => env('FAKE_FIAT_WEBHOOK_SECRET', ''),
    ],

];
