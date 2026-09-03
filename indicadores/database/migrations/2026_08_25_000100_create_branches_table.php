<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 20)->unique();
            $table->string('legal_name', 160);
            // Días ISO (1 = lunes … 7 = domingo) con conteo de inventario. Defecto: todos menos sábado (H3).
            $table->json('inventory_days');
            $table->unsignedTinyInteger('default_shifts')->default(3);
            $table->decimal('sales_deviation_pct', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
