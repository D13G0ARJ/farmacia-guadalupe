<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('status', 10)->default('normal'); // DayStatus

            // Los siete datos primarios (§2.2). Sin columnas derivadas (RN-03).
            $table->decimal('sales_bs', 14, 2);
            $table->decimal('exchange_rate', 12, 4); // snapshot (RN-06)
            $table->string('exchange_rate_source', 10); // RateSource al momento de guardar
            $table->unsignedInteger('transactions');
            $table->unsignedInteger('units');
            $table->unsignedInteger('inventory_units')->nullable();
            $table->decimal('inventory_value_usd', 14, 2)->nullable(); // en USD (RN-10, H2)
            $table->unsignedTinyInteger('shifts');

            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'date']); // RN-01
            $table->index(['branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_records');
    }
};
