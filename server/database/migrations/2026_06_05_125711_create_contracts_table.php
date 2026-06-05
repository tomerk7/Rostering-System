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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->unique()->constrained('workers')->cascadeOnDelete();
            $table->decimal('hourly_cost', 8, 2);
            $table->unsignedSmallInteger('min_monthly_hours');
            $table->unsignedSmallInteger('max_monthly_hours');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_hourly_cost_non_negative CHECK (hourly_cost >= 0)');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_max_monthly_hours_greater_than_or_equal_to_min CHECK (max_monthly_hours >= min_monthly_hours)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
