<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cupones', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('tipo', 20);
            $table->decimal('valor', 10, 2);
            $table->boolean('activo')->default(true);
            $table->timestamp('caduca_en')->nullable();
            $table->unsignedInteger('max_usos')->nullable();
            $table->unsignedInteger('usos_actuales')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cupones');
    }
};
