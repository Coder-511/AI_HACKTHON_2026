<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_registration_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('registry_subject_id')->nullable();
            $table->char('national_id_hash', 64);
            $table->char('contact_hash', 64)->nullable();
            $table->char('session_secret_hash', 64);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at')->index();
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_registration_challenges');
    }
};
