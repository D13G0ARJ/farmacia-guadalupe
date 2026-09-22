<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            // restrictOnDelete (no cascade): MySQL 8 no permite acción en cascada
            // sobre la columna base de una columna generada almacenada (branch_key).
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete(); // null = global
            $table->json('value');
            $table->timestamps();

            $table->unsignedBigInteger('branch_key')->storedAs('COALESCE(branch_id, 0)');
            $table->unique(['key', 'branch_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
