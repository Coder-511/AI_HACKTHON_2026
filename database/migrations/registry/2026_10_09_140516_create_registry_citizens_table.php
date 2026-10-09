<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'registry';

    public function up(): void
    {
        Schema::connection('registry')->create('registry_citizens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('national_id');
            $table->char('national_id_hash', 64)->unique();
            $table->text('full_name');
            $table->text('email');
            $table->string('governorate', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('registry')->dropIfExists('registry_citizens');
    }
};
