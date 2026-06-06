<?php

declare(strict_types=1);

use App\Enums\AssignmentSource;
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
        Schema::create('roster_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_id')->constrained('rosters')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->date('work_date');
            $table->string('source')->default(AssignmentSource::Auto->value);
            $table->timestamps();

            // A worker can't occupy the same shift slot twice on a day.
            $table->unique(['worker_id', 'work_date', 'shift_id']);

            // Per-slot demand checking / grid render and fill counts.
            $table->index(['roster_id', 'work_date', 'shift_id']);

            // No-3-shifts-per-day rule and per-worker monthly-hour totals.
            $table->index(['worker_id', 'work_date']);

            // Per-worker monthly hour aggregation within a roster.
            $table->index(['roster_id', 'worker_id']);
        });

        $allowedSources = implode(', ', array_map(
            static fn (string $source): string => "'".$source."'",
            AssignmentSource::values(),
        ));

        DB::statement("ALTER TABLE roster_assignments ADD CONSTRAINT roster_assignments_source_allowed CHECK (source IN ({$allowedSources}))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roster_assignments');
    }
};
