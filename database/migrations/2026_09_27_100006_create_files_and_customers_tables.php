<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private files: payment proofs, logos, statements, KYC documents.
        // Served only through short-lived signed URLs after an authorization check.
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->nullableUuidMorphs('attachable');
            $table->string('purpose', 30);
            $table->string('uploaded_by_type', 20);
            $table->uuid('uploaded_by_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['disk', 'path']);
        });

        Pg::check('files', 'files_purpose_check', Pg::in('purpose', ['payment_proof', 'logo', 'statement', 'kyc', 'settlement_proof', 'export']));
        Pg::check('files', 'files_uploader_check', Pg::in('uploaded_by_type', ['customer', 'user', 'system']));

        Schema::table('partners', function (Blueprint $table) {
            $table->foreign('logo_file_id')->references('id')->on('files');
        });

        // The partner's customers: only the reference and minimal details we need.
        Schema::create('partner_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained();
            $table->string('external_id', 100);
            $table->string('username', 150)->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestampTz('last_seen_at')->useCurrent();

            $table->unique(['partner_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_customers');

        Schema::table('partners', function (Blueprint $table) {
            $table->dropForeign(['logo_file_id']);
        });

        Schema::dropIfExists('files');
    }
};
