<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // When the host pressed "+10 s" on this vote: once per round, and ends_at has already moved.
    public function up(): void
    {
        Schema::table('category_vote_rounds', function (Blueprint $table) {
            $table->timestamp('extended_at', 3)->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('category_vote_rounds', function (Blueprint $table) {
            $table->dropColumn('extended_at');
        });
    }
};
