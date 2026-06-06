<?php

declare(strict_types=1);

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
        Schema::create('roster_generations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->string('status')->default('queued');
            $table->json('assignments')->nullable();
            $table->json('coverage_shortages')->nullable();
            $table->json('hours_shortfalls')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('roster_id')->nullable()->constrained('rosters')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Find the latest generation for a period and filter by lifecycle state.
            $table->index(['year', 'month', 'status']);
        });

        DB::statement('ALTER TABLE roster_generations ADD CONSTRAINT roster_generations_month_range CHECK (month BETWEEN 1 AND 12)');

        $allowedStatuses = implode(', ', array_map(
            static fn (string $status): string => "'".$status."'",
            ['queued', 'processing', 'completed', 'failed'],
        ));

        DB::statement("ALTER TABLE roster_generations ADD CONSTRAINT roster_generations_status_allowed CHECK (status IN ({$allowedStatuses}))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roster_generations');
    }
};
