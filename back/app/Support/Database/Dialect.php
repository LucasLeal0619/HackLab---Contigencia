<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Banco da conexão (HACKLAB-DEV-001). PostgreSQL é a produção e a referência; MySQL 8 é o desenvolvimento local
 * da equipe. Migrations e código usam isto para escolher o SQL equivalente — o caminho PostgreSQL nunca muda
 * (tests/Fixtures/pgsql-schema.json).
 */
final class Dialect
{
    /** Menor MySQL aceito: CHECK aplicado (8.0.16), índice funcional (8.0.13), DROP CONSTRAINT (8.0.19). */
    public const MYSQL_MINIMUM = '8.0.19';

    public static function isMysql(?Connection $connection = null): bool
    {
        return ($connection ?? DB::connection())->getDriverName() === 'mysql';
    }

    /**
     * Recusa servidor fora do aprovado: MariaDB (ex.: o do XAMPP) ou MySQL anterior a 8.0.19.
     *
     * @return string versão do servidor
     */
    public static function ensureSupportedMysql(?Connection $connection = null): string
    {
        $connection ??= DB::connection();
        $version = (string) $connection->selectOne('select version() as v')->v;

        if (stripos($version, 'mariadb') !== false) {
            throw new RuntimeException(sprintf(
                'Servidor MariaDB (%s) não é suportado. O HackLab usa MySQL Server %s ou maior: inicie o MySQL 8 e deixe o MySQL/MariaDB do XAMPP parado.',
                $version,
                self::MYSQL_MINIMUM,
            ));
        }

        $numeric = preg_match('/^\d+\.\d+\.\d+/', $version, $m) ? $m[0] : '0.0.0';
        if (version_compare($numeric, self::MYSQL_MINIMUM, '<')) {
            throw new RuntimeException(sprintf(
                'MySQL %s não é suportado. O HackLab exige MySQL Server %s ou maior (recomendado 8.4 LTS).',
                $version,
                self::MYSQL_MINIMUM,
            ));
        }

        return $version;
    }
}
