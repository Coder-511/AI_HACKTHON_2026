<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->string('risk_flag', 10)->nullable();
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->bigInteger('mfa_last_counter')->nullable();
        });
        Schema::create('user_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->text('reason');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type', 'revoked_at', 'ends_at']);
        });
        Schema::create('authority_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('municipality');
            $table->string('governorate', 30);
            $table->string('category', 30)->index();
            $table->boolean('active')->default(true);
            $table->boolean('simulated')->default(true);
            $table->timestamps();
        });
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('tracking_code', 30)->unique();
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->text('title');
            $table->text('description');
            $table->string('governorate', 30);
            $table->string('resolved_governorate', 30)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('category', 30)->nullable();
            $table->string('severity', 10)->default('low');
            $table->string('status', 40)->default('received')->index();
            $table->text('citizen_reason')->nullable();
            $table->text('guidance')->nullable();
            $table->boolean('external_ai_consent')->default(false);
            $table->timestamp('consented_at');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('authority_id')->nullable()->constrained('authority_units')->nullOnDelete();
            $table->uuid('duplicate_of')->nullable();
            $table->text('public_title')->nullable();
            $table->text('public_summary')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamp('anonymized_at')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['category', 'governorate', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('report_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 40);
            $table->string('sha256', 64)->index();
            $table->unsignedInteger('size');
            $table->string('scan_status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('report_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('message');
            $table->uuid('correlation_id');
            $table->timestamp('created_at');
        });
        Schema::create('report_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('report_version');
            $table->string('driver', 20);
            $table->text('result');
            $table->string('validation_state', 30);
            $table->timestamp('created_at');
            $table->unique(['report_id', 'report_version']);
        });
        Schema::create('dispatch_outboxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('authority_id')->constrained('authority_units')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('receipt', 100)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('appeals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 80)->index();
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'privacy_requests', 'audit_logs', 'news', 'appeals', 'dispatch_outboxes', 'report_analyses', 'report_events', 'report_attachments', 'reports', 'authority_units', 'user_restrictions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'risk_flag', 'mfa_secret', 'mfa_confirmed_at', 'mfa_last_counter']);
        });
    }
};
