<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Written in the same transaction as the debit, so a committed debit
        // always has a message telling a worker to send the payout.
        Schema::create('withdrawal_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_outbox');
    }
};
