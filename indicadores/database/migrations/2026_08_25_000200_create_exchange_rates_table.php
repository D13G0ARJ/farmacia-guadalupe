<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique(); // fecha de vigencia; la tasa es nacional (RN-08)
            $table->decimal('rate', 12, 4); // Bs por USD
            $table->string('source', 10); // bcv | manual | carried (RateSource)
            $table->timestamp('fetched_at')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
