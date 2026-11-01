<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('currency', 8);
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('reference_type', 50);
            $table->string('reference_id', 64);
            $table->enum('direction', ['debit', 'credit']);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->timestamp('created_at');

            $table->unique(['reference_type', 'reference_id', 'ledger_account_id', 'direction'], 'ledger_entries_posting_unique');
            $table->index(['ledger_account_id', 'direction']);
        });

        // Backstop for code that bypasses the model: the table is append-only.
        DB::unprepared("CREATE TRIGGER ledger_entries_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only'");
        DB::unprepared("CREATE TRIGGER ledger_entries_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only'");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
