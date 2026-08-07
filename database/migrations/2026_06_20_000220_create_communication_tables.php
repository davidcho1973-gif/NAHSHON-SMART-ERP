<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->string('scope', 40)->default('site')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 40)->default('active')->index();
            $table->boolean('is_read_only')->default(false);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_message_at')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'type', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('communication_room_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_room_id')->constrained('communication_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->string('role', 40)->default('member')->index();
            $table->string('status', 40)->default('active')->index();
            $table->timestampTz('joined_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestampTz('last_read_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['communication_room_id', 'employee_id'], 'comm_room_members_room_employee_unique');
            $table->unique(['communication_room_id', 'user_id'], 'comm_room_members_room_user_unique');
            $table->index(['employee_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('communication_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_room_id')->constrained('communication_rooms')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('communication_messages')->nullOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sender_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('kind', 40)->default('message')->index();
            $table->string('title')->nullable();
            $table->text('body');
            $table->nullableMorphs('related');
            $table->boolean('is_pinned')->default(false)->index();
            $table->string('priority', 40)->default('normal')->index();
            $table->string('status', 40)->default('active')->index();
            $table->timestampTz('sent_at')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['communication_room_id', 'parent_id', 'sent_at'], 'comm_messages_room_parent_sent_idx');
            $table->index(['site_id', 'kind', 'sent_at']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('communication_message_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_message_id')->constrained('communication_messages')->cascadeOnDelete();
            $table->foreignId('communication_room_id')->constrained('communication_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->timestampTz('read_at');
            $table->timestamps();

            $table->unique(['communication_message_id', 'employee_id'], 'comm_reads_message_employee_unique');
            $table->unique(['communication_message_id', 'user_id'], 'comm_reads_message_user_unique');
            $table->index(['communication_room_id', 'employee_id']);
            $table->index(['communication_room_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_message_reads');
        Schema::dropIfExists('communication_messages');
        Schema::dropIfExists('communication_room_members');
        Schema::dropIfExists('communication_rooms');
    }
};
