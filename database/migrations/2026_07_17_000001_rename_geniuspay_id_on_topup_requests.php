<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('topup_requests', 'geniuspay_id') && ! Schema::hasColumn('topup_requests', 'provider_ref')) {
            Schema::table('topup_requests', function (Blueprint $table) {
                $table->renameColumn('geniuspay_id', 'provider_ref');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('topup_requests', 'provider_ref') && ! Schema::hasColumn('topup_requests', 'geniuspay_id')) {
            Schema::table('topup_requests', function (Blueprint $table) {
                $table->renameColumn('provider_ref', 'geniuspay_id');
            });
        }
    }
};
