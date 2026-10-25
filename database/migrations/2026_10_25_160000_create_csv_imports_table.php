<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name', 180);
            $table->string('delimiter', 1);
            $table->json('headers');
            $table->json('mapping')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('next_row')->default(2);
            $table->unsignedBigInteger('data_offset')->default(0);
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->json('skipped')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_imports');
    }
};
