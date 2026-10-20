<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Simplificação pré-deploy: as filas de reconciliação (com ignorar/restaurar) e a pré-liberação de acesso para
 * Organização saíram do produto. O que é seguro é aplicado pela sincronização; as exceções são derivadas das
 * inscrições, sem tabela de decisão. A organização interna é cadastrada pelo Administrador.
 *
 * Remove as tabelas criadas pelas migrations 30 e 31 e devolve o token de primeiro acesso aos perfis JUROR/VOTER.
 * Nenhum dado de domínio se perde: Jurados e representantes criados continuam nas tabelas deles.
 * A down() recria as tabelas pelas migrations originais.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('drop trigger if exists account_access_grants_role_rules on account_access_grants');
        DB::unprepared('drop function if exists account_access_grants_role_rules()');
        Schema::dropIfExists('account_access_grants');
        Schema::dropIfExists('integration_reconciliations');

        // Tokens de perfis administrativos só existiam pela liberação de acesso: perdem o sentido.
        DB::statement("delete from account_activation_tokens where intended_role not in ('JUROR', 'VOTER')");
        DB::statement('alter table account_activation_tokens drop constraint account_activation_tokens_role_check');
        DB::statement("alter table account_activation_tokens add constraint account_activation_tokens_role_check check (intended_role in ('JUROR', 'VOTER'))");
    }

    public function down(): void
    {
        (require __DIR__.'/2026_10_19_000030_create_integration_reconciliations_table.php')->up();
        (require __DIR__.'/2026_10_19_000031_create_account_access_grants_table.php')->up();
    }
};
