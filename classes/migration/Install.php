<?php

/**
 * Creates the plugin's tables.
 *
 * No foreign keys to OJS tables on purpose: certificate and audit records must
 * survive deletion of a submission so the verification page can still say
 * "no longer available" instead of a bare 404.
 */

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes\migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Install extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bijphgr_certificates')) {
            Schema::create('bijphgr_certificates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id')->index();
                $table->bigInteger('submission_id')->index();
                $table->bigInteger('publication_id');
                $table->string('certificate_number', 64)->unique();
                $table->unsignedInteger('certificate_version')->default(1);
                // Public token printed in the QR code; grants access to the verification page only.
                $table->string('verify_token', 64)->unique();
                // Secret token e-mailed to the corresponding author; only its hash is stored.
                $table->string('download_token_hash', 64)->nullable()->index();
                $table->dateTime('download_token_expires_at')->nullable();
                // Path relative to the private certificate directory (never exposed).
                $table->string('file_path', 512)->nullable();
                $table->string('file_sha256', 64)->nullable();
                // valid | needs_revision | withdrawn
                $table->string('certificate_status', 24)->default('valid');
                // pending | queued | sent | failed
                $table->string('email_status', 16)->default('pending');
                $table->string('recipient_email', 255)->nullable();
                $table->unsignedInteger('email_attempts')->default(0);
                $table->dateTime('email_claimed_at')->nullable();
                $table->string('metadata_hash', 64)->nullable();
                $table->dateTime('generated_at')->nullable();
                $table->dateTime('sent_at')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');

                $table->unique(['context_id', 'publication_id'], 'bijphgr_cert_ctx_pub_unique');
            });
        }

        if (!Schema::hasTable('bijphgr_certificate_revisions')) {
            Schema::create('bijphgr_certificate_revisions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('certificate_id')->index();
                $table->unsignedInteger('version');
                $table->string('file_path', 512);
                $table->string('file_sha256', 64);
                $table->string('metadata_hash', 64);
                $table->text('reason')->nullable();
                $table->bigInteger('created_by')->nullable();
                $table->dateTime('created_at');

                $table->unique(['certificate_id', 'version'], 'bijphgr_cert_rev_unique');
            });
        }

        if (!Schema::hasTable('bijphgr_certificate_counters')) {
            Schema::create('bijphgr_certificate_counters', function (Blueprint $table) {
                $table->bigInteger('context_id');
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('last_value')->default(0);

                $table->primary(['context_id', 'year']);
            });
        }

        if (!Schema::hasTable('bijphgr_certificate_audit')) {
            Schema::create('bijphgr_certificate_audit', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('certificate_id')->nullable()->index();
                $table->bigInteger('context_id')->index();
                $table->string('event', 48);
                $table->text('detail')->nullable();
                $table->bigInteger('user_id')->nullable();
                $table->dateTime('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bijphgr_certificate_audit');
        Schema::dropIfExists('bijphgr_certificate_counters');
        Schema::dropIfExists('bijphgr_certificate_revisions');
        Schema::dropIfExists('bijphgr_certificates');
    }
}
