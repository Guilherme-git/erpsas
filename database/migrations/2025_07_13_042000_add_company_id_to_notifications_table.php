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
        // Clear existing notifications since we're adding company scoping
        DB::table('notifications')->truncate();

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->after('notifiable_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'notifiable_type', 'notifiable_id']);
        });
    }
};
