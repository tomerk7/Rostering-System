<?php

declare(strict_types=1);

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
        Schema::create('coverage_shortages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('roster_id')
                ->constrained('rosters')
                ->cascadeOnDelete();

            $table->date('work_date');
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->unsignedInteger('required_count');
            $table->unsignedInteger('assigned_count');

            $table->timestamps();

            $table->index(['roster_id', 'work_date', 'shift_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coverage_shortages');
    }
};
