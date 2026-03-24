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
        Schema::create('transcode_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->uuid('job_uuid')->unique();
            $table->string('operation_type', 20);
            $table->string('target_format', 20)->nullable();
            $table->string('target_resolution', 20)->nullable();
            $table->float('trim_start_sec')->nullable();
            $table->float('trim_end_sec')->nullable();
            $table->float('thumbnail_at_sec')->nullable();
            $table->string('output_path', 512)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->string('worker_id', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {}
};
