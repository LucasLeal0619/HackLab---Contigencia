<?php

namespace Tests\Unit;

use App\Support\Database\Dialect;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verificação do servidor MySQL antes das migrations (HACKLAB-DEV-001): só MySQL Server 8.0.19+; MariaDB
 * (ex.: o do XAMPP) e MySQL antigo são recusados com mensagem clara.
 */
class MysqlVersionCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    #[DataProvider('accepted')]
    public function test_accepts_mysql_8_0_19_or_newer(string $version): void
    {
        $this->assertSame($version, Dialect::ensureSupportedMysql($this->server($version)));
    }

    #[DataProvider('refused')]
    public function test_refuses_mariadb_and_older_mysql(string $version, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        Dialect::ensureSupportedMysql($this->server($version));
    }

    public static function accepted(): array
    {
        return [['8.0.19'], ['8.0.46'], ['8.4.6'], ['9.1.0'], ['8.0.46-0ubuntu0.22.04.1']];
    }

    public static function refused(): array
    {
        return [
            'MariaDB do XAMPP' => ['10.4.32-MariaDB', 'MariaDB'],
            'MariaDB recente' => ['11.4.2-MariaDB-log', 'MariaDB'],
            'MySQL 8.0.18' => ['8.0.18', '8.0.19'],
            'MySQL 5.7' => ['5.7.44', '8.0.19'],
        ];
    }

    private function server(string $version): Connection
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('selectOne')->with('select version() as v')->andReturn((object) ['v' => $version]);

        return $connection;
    }
}
