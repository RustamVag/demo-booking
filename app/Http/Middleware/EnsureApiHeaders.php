<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiHeaders
{
    /**
     * Заголовки, обязательные для любого API-запроса.
     *
     * @var list<string>
     */
    private const array REQUIRED_HEADERS = ['Accept', 'Authorization'];

    /**
     * Проверяет наличие заголовков Accept и Authorization.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $missingHeaders = array_values(array_filter(
            self::REQUIRED_HEADERS,
            fn (string $header): bool => ! $this->hasHeader($request, $header),
        ));

        if ($missingHeaders !== []) {
            return new JsonResponse([
                'message' => 'Запрос должен содержать заголовки: '.implode(', ', $missingHeaders).'.',
                'missing_headers' => $missingHeaders,
            ], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }

    /**
     * Заголовок считается переданным, только если он присутствует и не пустой.
     */
    private function hasHeader(Request $request, string $header): bool
    {
        return trim((string) $request->headers->get($header)) !== '';
    }
}
