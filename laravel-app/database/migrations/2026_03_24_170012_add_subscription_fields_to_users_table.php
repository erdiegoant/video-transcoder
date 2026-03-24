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
        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_tier', 20)->default('free')->after('password');
            $table->unsignedInteger('monthly_upload_count')->default(0)->after('subscription_tier');
            $table->timestamp('monthly_upload_reset')->nullable()->after('monthly_upload_count');
            $table->unsignedBigInteger('storage_used_bytes')->default(0)->after('monthly_upload_reset');
            $table->unsignedBigInteger('storage_limit_bytes')->default(524288000)->after('storage_used_bytes'); // 500 MB default
        });
    }

    public function down(): void {}
};
