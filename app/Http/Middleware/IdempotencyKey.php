<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client that sends an Idempotency-Key header can retry a money-moving
 * request safely. The key is reserved before the request runs and the
 * response is stored after it, so a retry gets the original response back
 * instead of moving money again. See ADR 0006.
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        $user = $request->user();
        if ($key === null || $user === null) {
            return $next($request);
        }

        if ($key === '' || strlen($key) > 255) {
            return response()->json(['message' => 'The Idempotency-Key header must be 1 to 255 characters.'], 422);
        }

        $userId = $user->getAuthIdentifier();
        $hash = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());

        $reserved = $this->db->table('idempotency_keys')->insertOrIgnore([
            'user_id' => $userId,
            'key' => $key,
            'request_hash' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        if (! $reserved) {
            return $this->replay($userId, $key, $hash);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            // The money operation rolled back; let the client retry with the same key.
            $this->stored($userId, $key)->delete();
        } else {
            $this->stored($userId, $key)->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);
        }

        return $response;
    }

    private function replay(mixed $userId, string $key, string $hash): Response
    {
        $stored = $this->stored($userId, $key)->first();

        if ($stored === null || $stored->request_hash !== $hash) {
            return response()->json(['message' => 'This Idempotency-Key was already used with a different request.'], 422);
        }

        // Reserved but never completed: either still running, or the process
        // died after the reservation. Refusing is safe; re-running might not be.
        if ($stored->response_status === null) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still being processed.'], 409);
        }

        return response((string) $stored->response_body, (int) $stored->response_status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function stored(mixed $userId, string $key): Builder
    {
        return $this->db->table('idempotency_keys')->where('user_id', $userId)->where('key', $key);
    }
}
