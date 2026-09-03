<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('period'); // primer día del mes
            $table->string('action', 10); // PeriodAction: closed | reopened
            $table->string('reason', 300)->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // El estado del mes es la última fila (RN-13).
            $table->index(['branch_id', 'period', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_events');
    }
};
