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
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('workflow_run_id');

            $table->string('step_name', 50); // prepare, analyze, process, etc.
            $table->string('status', 50); // pending, running, completed, failed, skipped

            // Step data
            $table->json('input_data')->nullable();
            $table->json('output_data')->nullable();
            $table->json('metadata')->nullable();

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();

            // Error tracking
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);

            $table->timestamps();

            // Foreign key
            $table->foreign('workflow_run_id')
                  ->references('id')
                  ->on('workflow_runs')
                  ->onDelete('cascade');

            // Indexes
            $table->index('workflow_run_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
