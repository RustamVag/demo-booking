<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ApiHeadersMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Запрос без заголовка Accept отклоняется с 400 ещё до аутентификации.
     */
    public function test_rejects_request_without_accept_header(): void
    {
        $this->withToken('test-token')
            ->get('/api/bookings', ['Accept' => ''])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('missing_headers', ['Accept']);
    }

    /**
     * Проверка заголовков выполняется раньше Sanctum: неавторизованный запрос
     * без Authorization получает 400, а не 401 или 500.
     */
    public function test_checks_headers_before_authentication(): void
    {
        $this->getJson('/api/bookings')
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('missing_headers', ['Authorization']);
    }

    /**
     * Если отсутствуют оба заголовка, в ответе перечислены оба.
     */
    public function test_rejects_request_without_both_required_headers(): void
    {
        $this->get('/api/bookings', ['Accept' => '', 'Authorization' => ''])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('missing_headers', ['Accept', 'Authorization']);
    }

    /**
     * Пустые значения заголовков считаются отсутствующими.
     */
    public function test_treats_empty_headers_as_missing(): void
    {
        $this->get('/api/bookings', ['Accept' => '   ', 'Authorization' => '   '])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('missing_headers', ['Accept', 'Authorization']);
    }

    /**
     * Запрос с обязательными заголовками проходит проверку middleware.
     */
    public function test_allows_request_with_required_headers(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->withToken('test-token')
            ->getJson('/api/bookings')
            ->assertOk();
    }
}
