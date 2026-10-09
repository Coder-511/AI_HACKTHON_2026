<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['email_verified_at', 'mfa_confirmed_at', 'created_at', 'updated_at'] as $column) {
                $table->dateTime($column)->nullable()->change();
            }
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dateTime('created_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['email_verified_at', 'mfa_confirmed_at', 'created_at', 'updated_at'] as $column) {
                $table->timestamp($column)->nullable()->change();
            }
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->timestamp('created_at')->change();
        });
    }
};
