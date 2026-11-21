<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El módulo de recetas no forma parte de los requisitos: se eliminan sus tablas.
     * Sus triggers de trazabilidad se eliminan junto con cada tabla.
     */
    public function up(): void
    {
        Schema::dropIfExists('recetas');
        Schema::dropIfExists('plantillas_receta');
    }

    /**
     * Restaura las tablas tal como quedaban tras las migraciones 2026_10_18_00000{2,3,4}.
     */
    public function down(): void
    {
        Schema::create('plantillas_receta', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre')->unique();
            $table->json('medicamentos');
            $table->text('indicaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('recetas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('paciente_id')->constrained('pacientes')->cascadeOnDelete();
            $table->foreignUuid('agenda_id')->nullable()->constrained('agenda')->nullOnDelete();
            $table->foreignUuid('emitida_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha');
            $table->text('diagnostico')->nullable();
            $table->json('medicamentos');
            $table->text('indicaciones')->nullable();
            $table->timestamps();
            $table->index(['paciente_id', 'fecha']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['plantillas_receta', 'recetas'] as $tabla) {
            DB::statement("ALTER TABLE public.{$tabla} SET SCHEMA clinica");
            DB::statement(
                "CREATE TRIGGER trazabilidad AFTER INSERT OR UPDATE OR DELETE ON clinica.{$tabla}
                 FOR EACH ROW EXECUTE FUNCTION auditoria.registrar_cambio()"
            );
        }
    }
};
