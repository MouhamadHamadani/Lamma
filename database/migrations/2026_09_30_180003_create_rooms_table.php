<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10)->default('lobby')->index(); // App\Enums\RoomStatus
            $table->json('settings'); // App\Game\RoomSettings
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('room_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            // Both nullable: a player is either a user or a guest (undecided, schema supports both).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_token', 64)->nullable();
            $table->string('nickname', 30);
            $table->string('locale', 2);
            $table->integer('score')->default(0);
            $table->boolean('is_ready')->default(false);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'user_id']);
            $table->unique(['room_id', 'guest_token']);
        });

        // Millisecond precision: the server owns the clock and answers are ranked by time.
        Schema::create('room_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained();
            $table->unsignedTinyInteger('position');
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('ends_at', 3)->nullable();
            $table->timestamp('revealed_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique(['room_id', 'position']);
        });

        Schema::create('player_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_option_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('answered_at', 3);
            $table->boolean('is_correct')->default(false);
            $table->integer('points')->default(0);
            $table->timestamps(3);

            $table->unique(['room_question_id', 'room_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_answers');
        Schema::dropIfExists('room_questions');
        Schema::dropIfExists('room_players');
        Schema::dropIfExists('rooms');
    }
};
