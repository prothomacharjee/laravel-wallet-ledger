<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency', 8);
            // Unsigned: even a bug that skips the funds check cannot store a negative balance.
            $table->unsignedBigInteger('balance')->default(0);
            $table->foreignId('ledger_account_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('to_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('wallets');
    }
};
