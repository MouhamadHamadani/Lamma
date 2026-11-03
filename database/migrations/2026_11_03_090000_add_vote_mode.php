<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vote mode (App\Game\CategoryVote): the host picks the first category and a pool; after every `vote_every` questions the players vote on the
 * category of the next batch. Existing rooms stay Classic (the default) and keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('game_mode', 10)->default('classic')->after('status'); // App\Enums\GameMode
            $table->unsignedTinyInteger('vote_every')->nullable()->after('game_mode');
            $table->foreignId('first_category_id')->nullable()->after('vote_every')->constrained('categories')->nullOnDelete();
        });

        // The top-level categories the players may vote between (the first category is one of them).
        Schema::create('room_category_pool', function (Blueprint $table) {
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->primary(['room_id', 'category_id']);
        });

        // One row per batch boundary ("round" r comes after question r x vote_every). Open while closed_at is null: that is the voting state, the way
        // countdown_ends_at is the countdown and revealed_at the reveal. option_ids keeps the display order, so every screen shows the same tiles.
        Schema::create('category_vote_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->json('option_ids');
            $table->timestamp('ends_at', 3)->nullable(); // null when there was no vote (only one option)
            $table->timestamp('closed_at', 3)->nullable();
            $table->foreignId('winner_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('outcome', 12)->nullable(); // App\Enums\VoteOutcome
            $table->timestamps(3);

            $table->unique(['room_id', 'round']);
        });

        Schema::create('category_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->foreignId('room_player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps(3);

            // One vote per player per round: the index decides a double tap.
            $table->unique(['room_id', 'round', 'room_player_id']);
            $table->index(['room_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_votes');
        Schema::dropIfExists('category_vote_rounds');
        Schema::dropIfExists('room_category_pool');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_category_id');
            $table->dropColumn(['game_mode', 'vote_every']);
        });
    }
};
