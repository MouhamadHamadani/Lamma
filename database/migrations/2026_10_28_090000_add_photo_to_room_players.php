<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A player's selfie belongs to ONE game: the path of its file on the `player_photos` disk (App\Game\PlayerPhoto), never a profile. */
    public function up(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('locale');
            $table->timestamp('avatar_updated_at')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'avatar_updated_at']);
        });
    }
};
