<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListUsuariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_25_012937_create_pacientes_table.php',
            '2026_09_25_163636_add_username_and_role_to_users_table.php',
            '2026_09_25_164339_add_paciente_id_to_users_table.php',
            '2026_09_30_034413_add_activo_to_users_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public function test_doctora_can_navigate_twenty_users_per_page_without_duplicates(): void
    {
        $ids = [];

        for ($numero = 1; $numero <= 25; $numero++) {
            $user = User::factory()->create([
                'id' => sprintf('00000000-0000-4000-8000-%012d', $numero),
                'username' => 'usuario'.$numero,
                'role' => $numero <= 5 ? 'asistente' : 'paciente',
                'activo' => $numero % 2 === 0,
                'created_at' => $numero <= 5 ? '2026-01-01 10:00:00' : '2026-01-02 10:00:00',
            ]);
            $ids[] = $user->id;
        }

        Sanctum::actingAs(new User(['role' => 'doctora']));

        $first = $this->getJson('/api/usuarios')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 25)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('prev_page_url', null)
            ->assertJsonPath('data.0.username', 'usuario25')
            ->assertJsonPath('data.0.activo', false)
            ->assertJsonStructure(['data' => ['*' => [
                'id', 'name', 'email', 'username', 'role', 'paciente_id', 'activo', 'created_at',
            ]]]);

        $second = $this->getJson($first->json('next_page_url'))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('next_page_url', null);

        $expected = array_reverse($ids);
        $this->assertSame(array_slice($expected, 0, 20), array_column($first->json('data'), 'id'));
        $this->assertSame(array_slice($expected, 20), array_column($second->json('data'), 'id'));
        $this->assertSame($first->json('first_page_url'), $second->json('prev_page_url'));

        foreach (array_merge($first->json('data'), $second->json('data')) as $user) {
            $this->assertArrayNotHasKey('password', $user);
            $this->assertArrayNotHasKey('remember_token', $user);
        }

        $this->getJson('/api/usuarios?page=3')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 25);
    }

    public function test_empty_list_keeps_pagination_metadata(): void
    {
        Sanctum::actingAs(new User(['role' => 'doctora']));

        $this->getJson('/api/usuarios')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('next_page_url', null);
    }

    public function test_guests_and_other_roles_cannot_list_users(): void
    {
        $this->getJson('/api/usuarios')->assertUnauthorized();

        foreach (['asistente', 'paciente'] as $role) {
            Sanctum::actingAs(new User(['role' => $role]));
            $this->getJson('/api/usuarios')->assertForbidden();
        }
    }
}
