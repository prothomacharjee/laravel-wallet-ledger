<?php

declare(strict_types=1);

return [

    /*
    | Circuit breaker: the most a single wallet may hold, per currency, as a
    | decimal string. A credit that would push a wallet past this is rejected
    | before any money moves.
    */
    'max_balance' => [
        'USD' => env('WALLET_MAX_BALANCE_USD', '50000.00'),
        'BDT' => env('WALLET_MAX_BALANCE_BDT', '5000000.00'),
        'BTC' => env('WALLET_MAX_BALANCE_BTC', '2.00000000'),
        'USDT' => env('WALLET_MAX_BALANCE_USDT', '50000.000000'),
    ],

    /*
    | How long a user's money-moving request holds the Redis submit lock, in
    | seconds. It is released as soon as the request finishes; the TTL only
    | matters if the worker dies mid-request.
    */
    'submit_lock_seconds' => 10,

];
