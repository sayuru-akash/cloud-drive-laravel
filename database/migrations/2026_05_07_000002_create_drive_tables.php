<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('parent_folder_id')->nullable()->index();
            $table->string('name');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('visibility')->default('private')->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('parent_folder_id')->references('id')->on('folders')->nullOnDelete();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('folder_id')->nullable()->index();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('display_name');
            $table->string('extension')->nullable()->index();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('visibility')->default('private')->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamp('deleted_at')->nullable();
            $table->string('current_version_id')->nullable();
            $table->timestamps();

            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
        });

        Schema::create('file_versions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('file_id')->index();
            $table->unsignedInteger('version_number');
            $table->string('storage_bucket');
            $table->string('storage_key')->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type');
            $table->string('checksum')->nullable();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['file_id', 'version_number']);
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
        });

        Schema::table('files', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('file_versions')->nullOnDelete();
        });

        Schema::create('uploads', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('file_id')->index();
            $table->foreignId('initiated_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('upload_status')->default('initiated')->index();
            $table->string('storage_key');
            $table->string('provider_upload_id')->nullable();
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
        });

        Schema::create('share_links', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('token_hash')->unique();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode')->default('download');
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('is_revoked')->default(false)->index();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();
            $table->string('action_type')->index();
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata_json')->default('{}');
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('app_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value')->default('{}');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('share_links');
        Schema::dropIfExists('uploads');
        Schema::table('files', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('file_versions');
        Schema::dropIfExists('files');
        Schema::dropIfExists('folders');
    }
};
