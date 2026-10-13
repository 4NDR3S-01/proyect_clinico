<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListPacientesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_09_25_012937_create_pacientes_table.php'))->up();
    }

    public function test_staff_can_navigate_twenty_patients_per_page_without_duplicates(): void
    {
        $ids = [];

        for ($numero = 1; $numero <= 25; $numero++) {
            $paciente = new Paciente([
                'codigo_paciente' => (string) $numero,
                'nombres' => 'Paciente '.$numero,
                'cedula' => (string) $numero,
            ]);
            $paciente->id = sprintf('00000000-0000-4000-8000-%012d', $numero);
            $paciente->created_at = $numero <= 5 ? '2026-01-01 10:00:00' : '2026-01-02 10:00:00';
            $paciente->save();
            $ids[] = $paciente->id;
        }

        $expected = array_reverse($ids);

        foreach (['doctora', 'asistente'] as $role) {
            Sanctum::actingAs(new User(['role' => $role]));

            $first = $this->getJson('/api/pacientes')
                ->assertOk()
                ->assertJsonCount(20, 'data')
                ->assertJsonPath('current_page', 1)
                ->assertJsonPath('per_page', 20)
                ->assertJsonPath('total', 25)
                ->assertJsonPath('last_page', 2)
                ->assertJsonPath('prev_page_url', null);

            $second = $this->getJson($first->json('next_page_url'))
                ->assertOk()
                ->assertJsonCount(5, 'data')
                ->assertJsonPath('current_page', 2)
                ->assertJsonPath('next_page_url', null);

            $this->assertSame(array_slice($expected, 0, 20), array_column($first->json('data'), 'id'));
            $this->assertSame(array_slice($expected, 20), array_column($second->json('data'), 'id'));
            $this->assertSame($first->json('first_page_url'), $second->json('prev_page_url'));

            $this->getJson('/api/pacientes?page=3')
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('total', 25);
        }
    }

    public function test_empty_list_keeps_pagination_metadata(): void
    {
        Sanctum::actingAs(new User(['role' => 'doctora']));

        $this->getJson('/api/pacientes')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('next_page_url', null);
    }

    public function test_guests_and_patients_cannot_list_patients(): void
    {
        $this->getJson('/api/pacientes')->assertUnauthorized();

        Sanctum::actingAs(new User(['role' => 'paciente']));
        $this->getJson('/api/pacientes')->assertForbidden();
    }
}
