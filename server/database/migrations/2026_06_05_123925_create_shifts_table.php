<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->char('code', 1)->unique();
            $table->string('label', 20);
            $table->time('start_time');
            $table->time('end_time');
            $table->smallInteger('duration_hours');
        });

        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_duration_hours_positive CHECK (duration_hours > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
