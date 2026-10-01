<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A nickname is unique within a room (MySQL's default collation also makes it case-insensitive).
    public function up(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->unique(['room_id', 'nickname']);
        });
    }

    public function down(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->dropUnique(['room_id', 'nickname']);
        });
    }
};
