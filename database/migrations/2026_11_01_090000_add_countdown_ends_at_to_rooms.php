<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** "Start game" opens a 3 · 2 · 1 · Go! countdown; question 1 starts when it ends. Milliseconds, like the question deadlines, so every screen counts together. */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('countdown_ends_at', 3)->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('countdown_ends_at');
        });
    }
};
