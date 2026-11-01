<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a user run one money-moving request at a time. A double click or a
 * client retry that arrives while the first request is still running gets
 * 429 here, before it opens a database transaction or takes a row lock.
 * This is a cheap first filter; correctness still comes from the row locks.
 */
final class SubmitLock
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $lock = Cache::lock('submit-lock:'.$user->getAuthIdentifier(), config()->integer('wallet.submit_lock_seconds'));

        if (! $lock->get()) {
            return response()->json(['message' => 'Another request from this user is still being processed.'], 429);
        }

        try {
            return $next($request);
        } finally {
            $lock->release();
        }
    }
}
