<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('purchase_request_enabled')->default(false);
            $table->boolean('purchase_buy_enabled')->default(false);
        });
        Schema::table('communication_notifications', function (Blueprint $table): void {
            $table->string('action_url', 1024)->nullable();
        });
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 100)->unique();
            $table->string('request_fingerprint', 64);
            $table->string('status', 32)->default('submitted');
            $table->unsignedInteger('version')->default(1);
            $table->date('need_by')->nullable();
            $table->text('note')->nullable();
            $table->string('reason', 80)->nullable();
            $table->date('eta')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status']);
            $table->index(['requested_by_id', 'created_at']);
        });
        Schema::create('purchase_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('specification')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32);
            $table->text('product_url')->nullable();
            $table->unsignedInteger('seq')->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('status', 32);
            $table->text('message');
            $table->jsonb('data')->nullable();
            $table->string('request_key', 100)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['purchase_request_id', 'request_key'], 'purchase_event_request_key_unique');
        });
        Schema::create('purchase_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose', 20)->default('request');
            $table->string('disk', 60);
            $table->string('path', 1024);
            $table->string('name', 255);
            $table->string('mime', 160);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
        Schema::create('purchase_receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_receipt_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->foreignId('allocated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['purchase_request_line_id', 'material_receipt_line_id'], 'purchase_receipt_line_unique');
        });
        Schema::create('purchase_request_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ordered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('evidence_id')->constrained('purchase_request_attachments')->restrictOnDelete();
            $table->string('order_number', 160);
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('vendor', 255);
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamp('ordered_at');
            $table->timestamps();
            $table->unique(['purchase_request_id', 'vendor', 'order_number'], 'purchase_order_number_unique');
        });
        Schema::create('purchase_request_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
            $table->unique(['purchase_request_order_id', 'purchase_request_line_id'], 'purchase_order_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_order_lines');
        Schema::dropIfExists('purchase_request_orders');
        Schema::dropIfExists('purchase_receipt_allocations');
        Schema::dropIfExists('purchase_request_attachments');
        Schema::dropIfExists('purchase_request_events');
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['purchase_request_enabled', 'purchase_buy_enabled']);
        });
        Schema::table('communication_notifications', fn (Blueprint $table) => $table->dropColumn('action_url'));
    }
};
