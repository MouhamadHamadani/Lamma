<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The shuffled order of the question's option ids, fixed once when the game starts so every screen shows the same A/B/C/D.
    public function up(): void
    {
        Schema::table('room_questions', function (Blueprint $table) {
            $table->json('option_order')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('room_questions', function (Blueprint $table) {
            $table->dropColumn('option_order');
        });
    }
};
