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
        Schema::create('contract_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->smallInteger('day_of_week');
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();

            $table->unique(['contract_id', 'day_of_week', 'shift_id']);
        });

        DB::statement('ALTER TABLE contract_availability ADD CONSTRAINT contract_availability_day_of_week_range CHECK (day_of_week BETWEEN 0 AND 6)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_availability');
    }
};
