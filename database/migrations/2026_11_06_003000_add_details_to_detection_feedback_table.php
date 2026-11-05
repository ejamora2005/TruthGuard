<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('detection_feedback', function (Blueprint $table) {
            $table->json('details')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('detection_feedback', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
