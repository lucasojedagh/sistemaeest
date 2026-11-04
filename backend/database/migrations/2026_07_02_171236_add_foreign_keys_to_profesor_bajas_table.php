<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('profesor_bajas', function (Blueprint $table) {
            $table->foreign(['profesor_id'])->references(['id'])->on('profesores')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profesor_bajas', function (Blueprint $table) {
            $table->dropForeign('profesor_bajas_profesor_id_foreign');
            $table->dropForeign('profesor_bajas_user_id_foreign');
        });
    }
};
