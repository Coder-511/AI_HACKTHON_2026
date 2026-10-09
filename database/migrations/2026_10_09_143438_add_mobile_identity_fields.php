<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('mobile_number')->nullable();
            $table->dateTime('mobile_verified_at')->nullable();
        });
        Schema::table('identity_registration_challenges', function (Blueprint $table): void {
            $table->char('mobile_hash', 64)->nullable();
            $table->dateTime('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('identity_registration_challenges', fn (Blueprint $table) => $table->dropColumn(['mobile_hash', 'verified_at']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['mobile_number', 'mobile_verified_at']));
    }
};
