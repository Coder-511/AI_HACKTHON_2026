<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('national_id')->nullable();
            $table->char('national_id_hash', 64)->nullable()->unique();
            $table->uuid('registry_subject_id')->nullable()->unique();
            $table->dateTime('identity_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['national_id_hash']);
            $table->dropUnique(['registry_subject_id']);
            $table->dropColumn(['national_id', 'national_id_hash', 'registry_subject_id', 'identity_verified_at']);
        });
    }
};
