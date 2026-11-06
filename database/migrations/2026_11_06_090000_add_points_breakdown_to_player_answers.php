<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // How `points` (still the total) was made up: (base_points + speed_bonus) x multiplier. Null on answers from before the speed bonus: they keep their stored points.
    public function up(): void
    {
        Schema::table('player_answers', function (Blueprint $table) {
            $table->unsignedSmallInteger('base_points')->nullable()->after('points');
            $table->unsignedSmallInteger('speed_bonus')->nullable()->after('base_points');
            $table->unsignedTinyInteger('multiplier')->nullable()->after('speed_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('player_answers', function (Blueprint $table) {
            $table->dropColumn(['base_points', 'speed_bonus', 'multiplier']);
        });
    }
};
