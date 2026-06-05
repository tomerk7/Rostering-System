<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->char('israeli_id', 9)->unique();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('role_id');
            $table->index(['is_active', 'role_id']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
