<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PointageTodayApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_today_returns_401_not_500(): void
    {
        // Handler prod masquait AuthenticationException en 500 ; on vérifie le chemin prod.
        $this->app['env'] = 'production';
        $this->withoutMiddleware(\App\Http\Middleware\ForceHttps::class);

        $response = $this->getJson('/api/pointage/today?lite=1');

        $response->assertUnauthorized();
        $this->assertNotSame(500, $response->status());
        $response->assertJsonMissing(['message' => 'Erreur serveur.']);
    }

    public function test_authenticated_today_returns_payload(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/pointage/today?lite=1');

        $response->assertOk()
            ->assertJsonPath('synced', true)
            ->assertJsonStructure([
                'date',
                'scheduled_arrival',
                'scheduled_departure',
            ]);
    }
}
