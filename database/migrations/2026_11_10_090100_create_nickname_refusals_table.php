<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many nicknames the filter turned away, per day (Phase 14.1): a COUNT, so the admin can see whether the list is doing anything or whether people keep trying. It holds
 * no nickname, no word, no room, no player and no address: there is nothing here to leak. Written by App\Game\NicknameRefusals when a join or a rename is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nickname_refusals', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('refused')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nickname_refusals');
    }
};
