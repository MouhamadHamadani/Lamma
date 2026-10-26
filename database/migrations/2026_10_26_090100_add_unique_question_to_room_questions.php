<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A question can be asked once per game. The picker already hands out distinct questions, so no existing room has a duplicate; if one
        // somehow does, stop with a clear message rather than delete anybody's game history.
        $duplicated = DB::table('room_questions')->select('room_id', 'question_id')->groupBy('room_id', 'question_id')->havingRaw('count(*) > 1')->get();
        if ($duplicated->isNotEmpty()) {
            throw new RuntimeException('room_questions has the same question twice in room(s) '.$duplicated->pluck('room_id')->unique()->implode(', ').': fix those rows, then migrate again.');
        }

        Schema::table('room_questions', function (Blueprint $table) {
            $table->unique(['room_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::table('room_questions', function (Blueprint $table) {
            $table->dropUnique(['room_id', 'question_id']);
        });
    }
};
