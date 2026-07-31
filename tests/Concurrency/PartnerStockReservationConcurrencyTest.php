<?php

namespace Tests\Concurrency;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('partner-concurrency')]
class PartnerStockReservationConcurrencyTest extends TestCase
{
    private const TABLE = 'partner_api_concurrency_stock_test';

    public function test_two_transactions_cannot_reserve_the_same_last_unit(): void
    {
        if (getenv('PARTNER_CONCURRENCY_TEST') !== '1') {
            $this->markTestSkipped('Opt-in only: set PARTNER_CONCURRENCY_TEST=1 for a dedicated *_test MySQL database.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the real concurrency test.');
        }

        $database = (string) getenv('DB_DATABASE');
        $connection = (string) getenv('DB_CONNECTION');
        if (! in_array($connection, ['mysql', 'mariadb'], true) || ! str_ends_with($database, '_test')) {
            throw new RuntimeException('Concurrency tests are blocked unless DB_DATABASE ends with _test and DB_CONNECTION is mysql or mariadb.');
        }

        $control = $this->connect();
        $control->exec('CREATE TABLE IF NOT EXISTS '.self::TABLE.' (id BIGINT UNSIGNED PRIMARY KEY, available_quantity INT NOT NULL) ENGINE=InnoDB');
        $control->exec('DELETE FROM '.self::TABLE);
        $control->exec('INSERT INTO '.self::TABLE.' (id, available_quantity) VALUES (1, 1)');

        $coordinationDir = sys_get_temp_dir().'/partner-api-concurrency-'.bin2hex(random_bytes(8));
        if (! mkdir($coordinationDir, 0700) && ! is_dir($coordinationDir)) {
            throw new RuntimeException('Could not create concurrency coordination directory.');
        }

        try {
            $firstPid = $this->forkReservation($coordinationDir, 'first', true);
            $this->waitForFile($coordinationDir.'/first.locked');
            $secondPid = $this->forkReservation($coordinationDir, 'second', false);
            pcntl_waitpid($firstPid, $firstStatus);
            pcntl_waitpid($secondPid, $secondStatus);

            $this->assertTrue(pcntl_wifexited($firstStatus) && pcntl_wexitstatus($firstStatus) === 0);
            $this->assertTrue(pcntl_wifexited($secondStatus) && pcntl_wexitstatus($secondStatus) === 0);
            $this->assertSame(1, (int) file_get_contents($coordinationDir.'/first.result'));
            $this->assertSame(0, (int) file_get_contents($coordinationDir.'/second.result'));
            $this->assertSame(0, (int) $control->query('SELECT available_quantity FROM '.self::TABLE.' WHERE id = 1')->fetchColumn());
        } finally {
            $control->exec('DROP TABLE IF EXISTS '.self::TABLE);
            foreach (glob($coordinationDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($coordinationDir);
        }
    }

    private function forkReservation(string $directory, string $name, bool $holdLock): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork concurrency worker.');
        }
        if ($pid > 0) {
            return $pid;
        }

        try {
            $connection = $this->connect();
            $connection->beginTransaction();
            $available = (int) $connection->query('SELECT available_quantity FROM '.self::TABLE.' WHERE id = 1 FOR UPDATE')->fetchColumn();
            file_put_contents($directory.'/'.$name.'.locked', '1', LOCK_EX);
            if ($holdLock) {
                usleep(500000);
            }
            $reserved = 0;
            if ($available > 0) {
                $connection->exec('UPDATE '.self::TABLE.' SET available_quantity = available_quantity - 1 WHERE id = 1');
                $reserved = 1;
            }
            $connection->commit();
            file_put_contents($directory.'/'.$name.'.result', (string) $reserved, LOCK_EX);
            exit(0);
        } catch (\Throwable) {
            exit(1);
        }
    }

    private function connect(): PDO
    {
        $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('DB_PORT') ?: '3306');
        $database = (string) getenv('DB_DATABASE');
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        return new PDO($dsn, (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for the first transaction lock.');
            }
            usleep(10000);
        }
    }
}
