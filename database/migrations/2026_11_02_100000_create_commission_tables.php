<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('partners')->restrictOnDelete();
            // Basis points: 2000 = 20.00%.
            $table->unsignedSmallInteger('rate_bps');
            $table->timestamps();
        });

        // One row per fee a partner earns commission on. The unique source
        // reference stops the same fee from being accrued twice.
        Schema::create('commission_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->string('source_reference')->unique();
            $table->unsignedBigInteger('fee_amount');
            $table->char('currency', 4);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['processed_at', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_accruals');
        Schema::dropIfExists('partners');
    }
};
