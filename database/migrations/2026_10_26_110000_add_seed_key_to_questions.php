<?php

use App\Support\SeedKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stable key ContentSeeder matches shipped questions by (a hash of the English text, see App\Support\SeedKey). Nullable: a question an
        // admin writes has none.
        Schema::table('questions', function (Blueprint $table) {
            $table->string('seed_key', 40)->nullable()->unique()->after('image_path');
        });

        $this->backfill();
    }

    /**
     * Every question that exists gets its key from its current English text, so the seeder recognises it and adds nothing twice. Two
     * questions with the same English text (only possible by hand) cannot share a key: the first keeps it. Public so a test can run it.
     */
    public function backfill(): void
    {
        $used = DB::table('questions')->whereNotNull('seed_key')->pluck('seed_key')->flip()->all();
        DB::table('questions')->orderBy('id')->chunkById(200, function ($questions) use (&$used) {
            foreach ($questions as $question) {
                $english = json_decode((string) $question->text, true)['en'] ?? null;
                if (! is_string($english) || trim($english) === '') {
                    continue;
                }

                $key = SeedKey::for($english);
                if (isset($used[$key])) {
                    continue;
                }

                $used[$key] = true;
                DB::table('questions')->where('id', $question->id)->update(['seed_key' => $key]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropUnique(['seed_key']);
            $table->dropColumn('seed_key');
        });
    }
};
