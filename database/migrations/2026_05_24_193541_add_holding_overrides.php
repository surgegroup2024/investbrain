<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('holdings', function (Blueprint $table) {
            $table->float('quantity_override', 12, 4)->nullable()->after('quantity');
            $table->float('avg_cost_override', 12, 4)->nullable()->after('average_cost_basis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('holdings', function (Blueprint $table) {
            $table->dropColumn(['quantity_override', 'avg_cost_override']);
        });
    }
};
