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
        Schema::create('project_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('description')->nullable();
            $table->string('color', 20)->default('zinc');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('project_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->string('kind', 20)->index();
            $table->string('color', 20)->default('zinc');
            $table->string('icon', 40)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('project_task_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->string('kind', 20)->index();
            $table->string('color', 20)->default('zinc');
            $table->string('icon', 40)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('project_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->unsignedSmallInteger('level')->unique();
            $table->string('color', 20)->default('zinc');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE project_statuses ADD CONSTRAINT project_statuses_kind_check CHECK (kind IN ('planned', 'active', 'paused', 'at_risk', 'completed', 'cancelled'))");
            DB::statement("ALTER TABLE project_task_statuses ADD CONSTRAINT project_task_statuses_kind_check CHECK (kind IN ('pending', 'in_progress', 'blocked', 'in_review', 'completed', 'cancelled'))");

            // At most one default row per catalog.
            DB::statement('CREATE UNIQUE INDEX project_statuses_single_default ON project_statuses (is_default) WHERE is_default');
            DB::statement('CREATE UNIQUE INDEX project_task_statuses_single_default ON project_task_statuses (is_default) WHERE is_default');
            DB::statement('CREATE UNIQUE INDEX project_priorities_single_default ON project_priorities (is_default) WHERE is_default');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_priorities');
        Schema::dropIfExists('project_task_statuses');
        Schema::dropIfExists('project_statuses');
        Schema::dropIfExists('project_categories');
    }
};
