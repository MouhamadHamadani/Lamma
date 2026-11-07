<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The no-signup demo (Phase 14). A demo room has no host account (rooms.host_id was already nullable): `is_demo` marks it and `host_token_hash` is the sha256
 * of the token in the host's encrypted cookie, the only way into /host/{code} of a demo. `room_players.is_bot` marks Sara and Karim.
 *
 * `lamma:prune` deletes a demo room after two hours, so what the dashboard's Funnel needs to remember about demos lives in `demo_stats`: one row per day with
 * how many were started and finished (tallied when the room is deleted) and how many people signed up on a device that had hosted a demo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('game_mode');
            $table->string('host_token_hash', 64)->nullable()->after('is_demo');
            $table->index(['is_demo', 'created_at']);
        });

        Schema::table('room_players', function (Blueprint $table) {
            $table->boolean('is_bot')->default(false)->after('locale');
        });

        Schema::create('demo_stats', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('started')->default(0);
            $table->unsignedInteger('finished')->default(0);
            $table->unsignedInteger('converted')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_stats');

        Schema::table('room_players', function (Blueprint $table) {
            $table->dropColumn('is_bot');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex(['is_demo', 'created_at']);
            $table->dropColumn(['is_demo', 'host_token_hash']);
        });
    }
};
