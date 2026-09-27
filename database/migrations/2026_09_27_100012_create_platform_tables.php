<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Default reason lists (editable later by Admin).
     *
     * @var array<string, array<string, string>>
     */
    private const REASON_CODES = [
        'payin_reject' => [
            'invalid_utr' => 'Invalid UTR',
            'wrong_amount' => 'Wrong amount',
            'duplicate_payment' => 'Duplicate payment',
            'payment_not_found' => 'Payment not found',
            'invalid_proof' => 'Invalid proof',
            'other' => 'Other',
        ],
        'payout_reject' => [
            'invalid_beneficiary' => 'Invalid beneficiary details',
            'bank_failure' => 'Bank transfer failed',
            'limit_exceeded' => 'Limit exceeded',
            'other' => 'Other',
        ],
    ];

    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 100)->unique();
            $table->string('title');
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::check('pages', 'pages_status_check', Pg::in('status', ['draft', 'published']));

        Schema::create('reason_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('context', 30);
            $table->string('code', 50);
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort')->default(0);

            $table->unique(['context', 'code']);
        });

        foreach (self::REASON_CODES as $context => $codes) {
            $sort = 0;
            foreach ($codes as $code => $label) {
                DB::table('reason_codes')->insert([
                    'id' => (string) Str::uuid7(), 'context' => $context, 'code' => $code,
                    'label' => $label, 'is_active' => true, 'sort' => $sort += 10,
                ]);
            }
        }

        Schema::create('verification_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->string('doc_type', 50);
            $table->foreignUuid('file_id')->constrained('files');
            $table->string('status', 20)->default('submitted');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
        });

        Pg::check('verification_documents', 'verification_documents_subject_check', Pg::in('subject_type', ['partner', 'branch', 'payment_account']));
        Pg::check('verification_documents', 'verification_documents_status_check', Pg::in('status', ['submitted', 'approved', 'rejected']));

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('verification_documents');
        Schema::dropIfExists('reason_codes');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('settings');
    }
};
