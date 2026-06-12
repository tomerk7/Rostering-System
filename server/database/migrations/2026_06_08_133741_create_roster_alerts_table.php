<?php

declare(strict_types=1);

use App\Enums\RosterAlertType;
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
        Schema::create('roster_alerts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('roster_id')
                ->constrained('rosters')
                ->cascadeOnDelete();

            $table->string('type');

            // Every alert is tied to a specific worker. worker_name is a snapshot
            // at write time so alerts survive worker deletion as historical records.
            $table->char('worker_id', 9);
            $table->string('worker_name')->nullable();
            $table->unsignedInteger('min_hours')->nullable();
            $table->unsignedInteger('scheduled_hours')->nullable();

            $table->timestamps();

            $table->index('worker_id');
        });

        $allowedTypes = implode(', ', array_map(
            static fn (string $type): string => "'".$type."'",
            RosterAlertType::values(),
        ));

        DB::statement("ALTER TABLE roster_alerts ADD CONSTRAINT roster_alerts_type_allowed CHECK (type IN ({$allowedTypes}))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roster_alerts');
    }
};
