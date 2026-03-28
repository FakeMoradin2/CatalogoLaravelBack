<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->string('cupon_codigo', 50)->nullable();
            $table->decimal('descuento', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropColumn(['cupon_codigo', 'descuento']);
        });
    }
};
