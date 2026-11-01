<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 32);
            $table->uuid('reference')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->string('destination', 255);
            $table->string('status', 16);
            $table->string('provider_transaction_id', 100)->nullable()->unique();
            $table->timestamps();
        });

        // Written outside the failed transaction: the gateway accepted a payout
        // that we could not commit locally, so we asked the gateway to cancel it.
        Schema::create('withdrawal_cancellations', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->uuid('reference');
            $table->string('provider_transaction_id', 100);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->text('reason');
            $table->boolean('cancel_accepted');
            $table->text('cancel_error')->nullable();
            $table->timestamps();
        });

        Schema::create('fake_gateway_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->uuid('reference')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->string('destination', 255);
            $table->string('transaction_id', 100)->unique();
            $table->string('status', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_gateway_withdrawals');
        Schema::dropIfExists('withdrawal_cancellations');
        Schema::dropIfExists('withdrawals');
    }
};
