<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Primeiro acesso (Complemento 11.1): pedido de ativação de conta de uma Person sem User.
 *
 * O token enviado por e-mail nunca é gravado: só o HMAC-SHA256 dele (token_hash). Um pedido vigente por
 * Person (novo pedido substitui o anterior). O User só nasce quando o token é consumido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_activation_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->unique()->constrained('people')->cascadeOnDelete();
            $table->string('requested_email');
            $table->string('intended_role', 16);
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("alter table account_activation_tokens add constraint account_activation_tokens_role_check check (intended_role in ('JUROR', 'VOTER'))");
        DB::statement('alter table account_activation_tokens add constraint account_activation_tokens_email_lowercase_check check (requested_email = lower(requested_email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activation_tokens');
    }
};
