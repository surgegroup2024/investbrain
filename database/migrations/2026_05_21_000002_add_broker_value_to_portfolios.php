<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->decimal('broker_value', 14, 2)->nullable()->after('account_type');
            $table->timestamp('broker_value_updated_at')->nullable()->after('broker_value');
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['broker_value', 'broker_value_updated_at']);
        });
    }
};
