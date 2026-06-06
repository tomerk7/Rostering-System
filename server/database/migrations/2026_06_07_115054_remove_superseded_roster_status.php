<?php

declare(strict_types=1);

use App\Enums\RosterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('rosters')
            ->where('status', 'superseded')
            ->delete();

        DB::statement('ALTER TABLE rosters DROP CONSTRAINT rosters_status_allowed');

        $allowedStatuses = implode(', ', array_map(
            static fn (string $status): string => "'".$status."'",
            RosterStatus::values(),
        ));

        DB::statement("ALTER TABLE rosters ADD CONSTRAINT rosters_status_allowed CHECK (status IN ({$allowedStatuses}))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE rosters DROP CONSTRAINT rosters_status_allowed');

        DB::statement("ALTER TABLE rosters ADD CONSTRAINT rosters_status_allowed CHECK (status IN ('draft', 'published', 'superseded'))");
    }
};
