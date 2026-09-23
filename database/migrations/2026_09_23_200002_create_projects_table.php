<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->text('scope')->nullable();

            $table->foreignId('category_id')->constrained('project_categories')->restrictOnDelete();
            $table->foreignId('status_id')->constrained('project_statuses')->restrictOnDelete();
            $table->foreignId('priority_id')->constrained('project_priorities')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();

            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('progress_mode', 10)->default('tasks');
            $table->decimal('budget', 15, 2)->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status_id');
            $table->index('priority_id');
            $table->index('category_id');
            $table->index('owner_id');
            $table->index('due_date');
            $table->index('archived_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_progress_check CHECK (progress BETWEEN 0 AND 100)');
            DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_progress_mode_check CHECK (progress_mode IN ('manual', 'tasks'))");
            DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_dates_check CHECK (due_date IS NULL OR start_date IS NULL OR due_date >= start_date)');
            DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_budget_check CHECK (budget IS NULL OR budget >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
