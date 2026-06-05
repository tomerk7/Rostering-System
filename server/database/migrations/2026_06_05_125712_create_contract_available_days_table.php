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
        Schema::create('contract_available_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->smallInteger('day_of_week');

            $table->unique(['contract_id', 'day_of_week']);
        });

        DB::statement('ALTER TABLE contract_available_days ADD CONSTRAINT contract_available_days_day_of_week_range CHECK (day_of_week BETWEEN 0 AND 6)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_available_days');
    }
};
