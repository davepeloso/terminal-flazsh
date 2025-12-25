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
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('project_name');
            $table->text('image_directory');
            $table->text('output_directory')->nullable();

            // Configuration snapshot
            $table->json('config');

            // State tracking
            $table->string('status', 50); // pending, running, completed, failed, paused
            $table->string('current_step', 50)->nullable(); // prepare, analyze, process, etc.

            // Cloud tracking
            $table->uuid('imagen_project_uuid')->nullable();
            $table->boolean('process_only')->default(false);

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Results
            $table->integer('total_images_processed')->default(0);
            $table->integer('total_groups_created')->default(0);
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
