<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Phase 13. questions.type: multiple_choice (every question so far), true_false or closest_number. A closest-number question keeps its correct value in
    // answer_number and the distance that still scores in tolerance; `explanation` is the optional {ar, en} fun fact shown with the answer. (A picture
    // question is any question with an image_path, a column that already existed.) A closest-number answer keeps the guess in number_value (there is no
    // option to point at), and the +50 for the closest guess in closest_bonus; both are null on every other answer.
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('type', 20)->default('multiple_choice')->after('difficulty')->index();
            $table->decimal('answer_number', 20, 4)->nullable()->after('image_path');
            $table->decimal('tolerance', 20, 4)->nullable()->after('answer_number');
            $table->json('explanation')->nullable()->after('tolerance');
        });

        Schema::table('player_answers', function (Blueprint $table) {
            $table->decimal('number_value', 20, 4)->nullable()->after('question_option_id');
            $table->unsignedSmallInteger('closest_bonus')->nullable()->after('speed_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('player_answers', function (Blueprint $table) {
            $table->dropColumn(['number_value', 'closest_bonus']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'answer_number', 'tolerance', 'explanation']);
        });
    }
};
