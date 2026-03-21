<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pedido_items')) {
            return;
        }

        DB::statement('ALTER TABLE pedido_items MODIFY producto_id INT UNSIGNED NOT NULL');

        $database = DB::getDatabaseName();

        $pedidoFk = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'pedido_items')
            ->where('COLUMN_NAME', 'pedido_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        $productoFk = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'pedido_items')
            ->where('COLUMN_NAME', 'producto_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        Schema::table('pedido_items', function (Blueprint $table) use ($pedidoFk, $productoFk): void {
            if (!$pedidoFk) {
                $table->foreign('pedido_id')
                    ->references('id')
                    ->on('pedidos')
                    ->cascadeOnDelete();
            }

            if (!$productoFk) {
                $table->foreign('producto_id')
                    ->references('id')
                    ->on('productos')
                    ->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        //
    }
};
