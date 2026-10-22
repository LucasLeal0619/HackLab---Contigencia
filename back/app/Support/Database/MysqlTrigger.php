<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Triggers MySQL equivalentes aos do PostgreSQL (HACKLAB-DEV-001, etapa 5). Só usado no caminho MySQL das migrations.
 *
 * Diferenças do MySQL tratadas aqui:
 * - um trigger por evento: "insert or update or delete" do PostgreSQL vira <nome>_ins / _upd / _del;
 * - "raise exception" vira SIGNAL SQLSTATE '45000' com a MESMA mensagem (o código do erro muda; nada depende dele);
 * - a ordem entre triggers do mesmo momento (no PostgreSQL, alfabética) é fixada com FOLLOWS/PRECEDES.
 */
final class MysqlTrigger
{
    /** Sufixos dos triggers divididos por evento. */
    public const SUFFIX = ['insert' => '_ins', 'update' => '_upd', 'delete' => '_del'];

    /**
     * @param  string  $event  insert | update | delete
     * @param  string|null  $order  ex.: "follows tasks_assignee_coherent_upd"
     */
    public static function create(string $name, string $event, string $table, string $body, ?string $order = null): void
    {
        DB::unprepared(sprintf(
            "create trigger %s before %s on %s for each row %s\nbegin\n%s\nend",
            $name,
            $event,
            $table,
            $order ?? '',
            trim($body),
        ));
    }

    /** SIGNAL com mensagem literal (as mesmas mensagens do PostgreSQL). */
    public static function fail(string $message): string
    {
        return "signal sqlstate '45000' set message_text = ".self::quote($message).';';
    }

    /** SIGNAL com mensagem montada em SQL (ex.: concat(...)); exige "declare msg varchar(255);" no corpo. */
    public static function failWith(string $sqlExpression): string
    {
        return "set msg = {$sqlExpression};\nsignal sqlstate '45000' set message_text = msg;";
    }

    public static function quote(string $text): string
    {
        return "'".str_replace("'", "''", $text)."'";
    }
}
