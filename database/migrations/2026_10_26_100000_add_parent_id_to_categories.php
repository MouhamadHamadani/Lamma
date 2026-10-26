<?php

use Database\Seeders\ContentSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subcategories: one level only (a category is top-level or sits under a top-level one; App\Models\Category enforces it).
        // Every existing category stays top-level with its questions, so nothing that exists changes. A parent cannot be deleted while
        // it has subcategories (restrict), so questions are never lost by deleting a parent by accident.
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('categories')->restrictOnDelete();
        });

        $this->restoreParents();
    }

    /**
     * Rolled back and forward again (or run against a copy of a database that already has them): the shipped subcategories ContentSeeder made earlier are still
     * there as plain top-level categories, because dropping the column forgot their parent. Put them back under it. A no-op on a fresh install and on the first
     * deploy of this feature, where none exist yet. It runs once, as part of this migration: ContentSeeder itself never changes a category. Public so a test can run it.
     */
    public function restoreParents(): void
    {
        foreach (ContentSeeder::data()['categories'] as $slug => $definition) {
            $parent = isset($definition['parent']) ? DB::table('categories')->where('slug', $definition['parent'])->whereNull('parent_id')->value('id') : null;
            if ($parent !== null) {
                DB::table('categories')->where('slug', $slug)->whereNull('parent_id')->update(['parent_id' => $parent]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
