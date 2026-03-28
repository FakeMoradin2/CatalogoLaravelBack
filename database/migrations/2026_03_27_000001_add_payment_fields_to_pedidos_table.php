<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->string('transaccion_id', 80)->nullable()->after('total');
            $table->string('payment_status', 30)->default('pendiente')->after('transaccion_id');
            $table->timestamp('paid_at')->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropColumn(['transaccion_id', 'payment_status', 'paid_at']);
        });
    }
};
