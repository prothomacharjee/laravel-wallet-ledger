<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 32);
            $table->uuid('reference')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->string('status', 16);
            // A provider transaction can settle at most one deposit, once.
            $table->string('provider_transaction_id', 100)->nullable()->unique();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // 1 while pending, NULL otherwise. MySQL allows many NULLs in a unique
            // index, so this permits any number of settled deposits but only one
            // pending deposit per user per payment method.
            $table->boolean('pending_flag')->nullable()->storedAs("IF(status = 'pending', 1, NULL)");
            $table->unique(['user_id', 'gateway', 'pending_flag'], 'deposits_one_pending_per_method');
        });

        Schema::create('webhook_calls', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->boolean('signature_valid');
            $table->string('status', 16);
            $table->text('payload');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // The fake gateways' own records: what the provider would hold on its side.
        Schema::create('fake_gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->uuid('deposit_reference')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->string('transaction_id', 100)->nullable()->unique();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_gateway_payments');
        Schema::dropIfExists('webhook_calls');
        Schema::dropIfExists('deposits');
    }
};
