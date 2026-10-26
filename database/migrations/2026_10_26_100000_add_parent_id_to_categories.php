<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
