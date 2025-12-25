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
        Schema::create('workflow_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('workflow_run_id');

            // File info
            $table->text('original_path');
            $table->text('processed_path')->nullable();
            $table->string('file_type', 50); // ambient, flash, blended
            $table->integer('group_number')->nullable();

            // EXIF metadata
            $table->json('exif_data')->nullable();

            // Processing
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            // Foreign key
            $table->foreign('workflow_run_id')
                  ->references('id')
                  ->on('workflow_runs')
                  ->onDelete('cascade');

            // Indexes
            $table->index('workflow_run_id');
            $table->index('file_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_files');
    }
};
