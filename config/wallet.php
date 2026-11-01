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
    | Per-user withdrawal limits, per currency, as decimal strings. "daily"
    | counts from midnight and "monthly" from the 1st, in the app timezone.
    | Payouts that failed and were refunded do not count.
    */
    'withdrawal_limits' => [
        'USD' => ['daily' => env('WITHDRAWAL_DAILY_LIMIT_USD', '5000.00'), 'monthly' => env('WITHDRAWAL_MONTHLY_LIMIT_USD', '20000.00')],
        'BDT' => ['daily' => env('WITHDRAWAL_DAILY_LIMIT_BDT', '500000.00'), 'monthly' => env('WITHDRAWAL_MONTHLY_LIMIT_BDT', '2000000.00')],
        'BTC' => ['daily' => env('WITHDRAWAL_DAILY_LIMIT_BTC', '0.50000000'), 'monthly' => env('WITHDRAWAL_MONTHLY_LIMIT_BTC', '2.00000000')],
        'USDT' => ['daily' => env('WITHDRAWAL_DAILY_LIMIT_USDT', '5000.000000'), 'monthly' => env('WITHDRAWAL_MONTHLY_LIMIT_USDT', '20000.000000')],
    ],

    /*
    | How long a user's money-moving request holds the Redis submit lock, in
    | seconds. It is released as soon as the request finishes; the TTL only
    | matters if the worker dies mid-request.
    */
    'submit_lock_seconds' => 10,

];
