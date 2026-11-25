<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Práctica 3.2 (William Cabrera): bitácora de enrutamiento. Cada mensaje que
 * entra deja una fila con la decisión (invalido, filtrado, enrutado,
 * no_enrutable o error_transporte), la regla aplicada y, si llegó a un
 * consumidor, cuál lo atendió y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensajes_enrutados', function (Blueprint $table) {
            $table->id();
            $table->string('mensaje_id', 64)->nullable()->index();
            $table->string('correlation_id', 64)->index();
            $table->string('tipo', 40)->nullable();
            $table->string('prioridad', 10)->nullable();
            $table->uuid('cita_id')->nullable();
            $table->string('canal', 10)->nullable();
            $table->string('decision', 20);
            $table->string('regla', 5)->nullable();
            $table->string('motivo')->nullable();
            $table->string('destino', 30)->nullable();
            $table->string('routing_key', 60)->nullable();
            $table->string('cola', 80)->nullable();
            $table->string('consumido_por', 60)->nullable();
            $table->timestamp('consumido_en', 3)->nullable();
            $table->text('resultado')->nullable();
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensajes_enrutados');
    }
};
