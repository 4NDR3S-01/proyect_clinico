<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Práctica 3.1: estado de cada notificación de cita enviada por la cola.
 * Permite seguir un mensaje por su message_id y toda la operación por su correlation_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones_cita', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->string('correlation_id', 64)->index();
            $table->uuid('cita_id')->index();
            $table->string('canal', 10);
            $table->string('modo', 10); // asincrono | sincrono
            $table->string('estado', 15); // pendiente, no_publicada, procesando, enviada, descartada, fallida
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->string('destino')->nullable(); // enmascarado
            $table->text('mensaje')->nullable();
            $table->string('detalle')->nullable();
            $table->timestamp('publicada_en', 3)->nullable();
            $table->timestamp('procesada_en', 3)->nullable();
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_cita');
    }
};
