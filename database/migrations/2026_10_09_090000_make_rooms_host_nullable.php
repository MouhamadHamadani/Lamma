<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A host who deletes their account must not take the finished games (and every other player's results) with them: the room keeps
 * existing with no host instead of cascading away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['host_id']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('host_id')->nullable()->change();
            $table->foreign('host_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // A room without a host cannot go back to NOT NULL.
        DB::table('rooms')->whereNull('host_id')->delete();

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['host_id']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('host_id')->nullable(false)->change();
            $table->foreign('host_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
