<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('portfolio_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 10);
            $table->enum('action', ['SELL_TO_OPEN', 'BUY_TO_CLOSE', 'BUY_TO_OPEN', 'SELL_TO_CLOSE']);
            $table->enum('option_type', ['CALL', 'PUT']);
            $table->integer('contracts');
            $table->decimal('strike_price', 14, 4);
            $table->date('expiration_date');
            $table->decimal('premium_per_share', 14, 4);
            $table->decimal('total_premium', 14, 2);
            $table->string('currency', 10)->default('USD');
            $table->date('date');
            $table->string('description')->nullable();
            $table->string('external_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['portfolio_id', 'symbol']);
            $table->index(['portfolio_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_activities');
    }
};
