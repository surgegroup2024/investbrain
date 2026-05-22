<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('portfolio_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['DEPOSIT', 'WITHDRAWAL']);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('USD');
            $table->date('date');
            $table->string('description')->nullable();
            $table->string('external_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['portfolio_id', 'date']);
            $table->index(['portfolio_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flows');
    }
};
