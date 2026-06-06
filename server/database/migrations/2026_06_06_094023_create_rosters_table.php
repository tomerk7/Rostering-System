<?php

declare(strict_types=1);

use App\Enums\RosterStatus;
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
        Schema::create('rosters', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->string('status')->default(RosterStatus::Draft->value);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE rosters ADD CONSTRAINT rosters_month_range CHECK (month BETWEEN 1 AND 12)');

        $allowedStatuses = implode(', ', array_map(
            static fn (string $status): string => "'".$status."'",
            RosterStatus::values(),
        ));

        DB::statement("ALTER TABLE rosters ADD CONSTRAINT rosters_status_allowed CHECK (status IN ({$allowedStatuses}))");

        // Unlimited drafts, at most one published roster per (year, month).
        DB::statement("CREATE UNIQUE INDEX rosters_published_year_month_unique ON rosters (year, month) WHERE status = 'published'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};
