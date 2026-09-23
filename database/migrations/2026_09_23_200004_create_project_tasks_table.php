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
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Subtasks: a task may hang from another task of the same project.
            $table->foreignId('parent_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();

            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('status_id')->constrained('project_task_statuses')->restrictOnDelete();
            $table->foreignId('priority_id')->constrained('project_priorities')->restrictOnDelete();

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();

            $table->unsignedTinyInteger('progress')->default(0);
            // Relative weight of the task in the project's calculated progress.
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status_id']);
            $table->index(['project_id', 'sort_order']);
            $table->index('parent_id');
            $table->index('assignee_id');
            $table->index('status_id');
            $table->index('due_date');
        });

        Schema::create('project_task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_id')->constrained('project_tasks')->cascadeOnDelete();
            // FS = finish-to-start. Other types (SS, FF, SF) can be enabled later without schema changes.
            $table->string('type', 2)->default('FS');
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_id']);
            $table->index('depends_on_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE project_tasks ADD CONSTRAINT project_tasks_progress_check CHECK (progress BETWEEN 0 AND 100)');
            DB::statement('ALTER TABLE project_tasks ADD CONSTRAINT project_tasks_weight_check CHECK (weight > 0)');
            DB::statement('ALTER TABLE project_tasks ADD CONSTRAINT project_tasks_dates_check CHECK (due_date IS NULL OR start_date IS NULL OR due_date >= start_date)');
            DB::statement('ALTER TABLE project_tasks ADD CONSTRAINT project_tasks_parent_check CHECK (parent_id IS NULL OR parent_id <> id)');
            DB::statement('ALTER TABLE project_task_dependencies ADD CONSTRAINT project_task_dependencies_self_check CHECK (task_id <> depends_on_id)');
            DB::statement("ALTER TABLE project_task_dependencies ADD CONSTRAINT project_task_dependencies_type_check CHECK (type IN ('FS', 'SS', 'FF', 'SF'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_task_dependencies');
        Schema::dropIfExists('project_tasks');
    }
};
