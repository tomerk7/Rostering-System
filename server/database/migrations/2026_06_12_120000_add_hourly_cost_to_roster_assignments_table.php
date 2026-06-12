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
        Schema::table('roster_assignments', function (Blueprint $table) {
            $table->decimal('hourly_cost', 8, 2)->nullable()->after('source');
        });

        // Snapshot the current contract rate onto existing assignments. Rows
        // whose worker no longer has a contract fall back to 0 so the column
        // can be made NOT NULL.
        DB::statement(
            'UPDATE roster_assignments SET hourly_cost = contracts.hourly_cost
             FROM contracts WHERE contracts.worker_id = roster_assignments.worker_id',
        );
        DB::statement('UPDATE roster_assignments SET hourly_cost = 0 WHERE hourly_cost IS NULL');

        DB::statement('ALTER TABLE roster_assignments ALTER COLUMN hourly_cost SET NOT NULL');
        DB::statement('ALTER TABLE roster_assignments ADD CONSTRAINT roster_assignments_hourly_cost_non_negative CHECK (hourly_cost >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE roster_assignments DROP CONSTRAINT roster_assignments_hourly_cost_non_negative');

        Schema::table('roster_assignments', function (Blueprint $table) {
            $table->dropColumn('hourly_cost');
        });
    }
};
