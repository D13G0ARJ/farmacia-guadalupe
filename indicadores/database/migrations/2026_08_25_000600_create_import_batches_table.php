<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('group_id'); // archivos subidos en una misma operación
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_filename', 255);
            $table->char('file_hash', 64); // sha256 (RN-21)
            $table->date('period')->nullable(); // mes detectado
            $table->string('status', 10); // ImportStatus
            $table->json('summary')->nullable();
            $table->json('parsed_payload')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'period']);
            $table->index('file_hash');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
