<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email preference per user (in-app notifications are always on) and a
     * record of daily digests already sent, so the scheduler never sends
     * the same digest twice.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications')->default(true)->after('deactivated_at');
        });

        Schema::create('project_alert_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('digest_date');
            $table->unsignedSmallInteger('items')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'digest_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_alert_digests');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_notifications');
        });
    }
};
