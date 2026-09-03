<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete(); // null = consolidado
            $table->string('indicator', 40); // Indicator enum
            $table->date('period'); // primer día del mes
            $table->string('period_type', 10)->default('month'); // previsto para P10 (semanal), no expuesto
            $table->decimal('target', 14, 4);
            $table->string('currency', 4); // Currency
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // MySQL permite varios NULL en índices únicos: la columna generada evita metas duplicadas del consolidado (§5.2).
            $table->unsignedBigInteger('branch_key')->storedAs('COALESCE(branch_id, 0)');
            $table->unique(['branch_key', 'indicator', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
