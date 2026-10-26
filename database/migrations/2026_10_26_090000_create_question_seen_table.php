<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per participant per revealed question: who has already met a question, so the next game can prefer fresh ones.
        // A participant is a user (user_id) or a guest (guest_token, forgotten by lamma:prune after the claim window).
        Schema::create('question_seen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_token', 64)->nullable();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at');

            $table->index(['user_id', 'question_id']);
            $table->index(['guest_token', 'question_id']);
            $table->index('seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_seen');
    }
};
