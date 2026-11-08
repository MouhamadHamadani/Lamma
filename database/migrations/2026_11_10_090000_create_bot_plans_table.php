<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A demo bot's plan for one question (Phase 14.1): WHEN it will answer and whether it will be right, written when the question opens. The BotAnswer job reads the same
 * moment from its own delay, and GameEngine::tick() (the host screen's 2-second poll) answers for every bot whose moment has passed, so a demo does not depend on the queue
 * worker. `answered_at` is set once the bot has answered (or can no longer), so neither path picks the plan up again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamp('answer_at', 3);
            $table->boolean('correct');
            $table->timestamp('answered_at', 3)->nullable();
            $table->unique(['room_player_id', 'position']);
            $table->index(['room_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_plans');
    }
};
