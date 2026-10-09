<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'registry';

    public function up(): void
    {
        Schema::connection('registry')->table('registry_citizens', function (Blueprint $table): void {
            $table->text('mobile_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('registry')->table('registry_citizens', fn (Blueprint $table) => $table->dropColumn('mobile_number'));
    }
};
