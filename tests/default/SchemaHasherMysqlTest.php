<?php

declare(strict_types=1);

namespace staabm\PHPStanDba\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use staabm\PHPStanDba\DbSchema\SchemaHasherMysql;

final class SchemaHasherMysqlTest extends TestCase
{
    private const DATABASE_NAME = 'phpstan_dba_schema_hash_test';

    private ?PDO $connection = null;

    protected function setUp(): void
    {
        if (! \in_array(self::env('DBA_REFLECTOR', ''), ['mysqli', 'pdo-mysql'], true)) {
            self::markTestSkipped('MySQL reflector required.');
        }

        if (false === strpos(self::env('DBA_MODE', ''), 'recording')) {
            self::markTestSkipped('Recording mode required, hashing a schema needs a database connection.');
        }

        if (! \extension_loaded('pdo_mysql')) {
            self::markTestSkipped('ext-pdo_mysql required.');
        }

        // hash a database of our own, so the tests are independent of the schema the other tests use
        $this->connection = self::createPdo(null);
        $this->connection->exec('DROP DATABASE IF EXISTS ' . self::DATABASE_NAME);
        $this->connection->exec('CREATE DATABASE ' . self::DATABASE_NAME);
        $this->connection->exec('USE ' . self::DATABASE_NAME);
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            $this->connection->exec('DROP DATABASE IF EXISTS ' . self::DATABASE_NAME);
            $this->connection = null;
        }
    }

    public function testSchemaHashIgnoresTheOrderColumnsAreDeclaredIn(): void
    {
        $this->exec('CREATE TABLE t (zebra int NOT NULL, apple varchar(10) NULL)');
        $hash = self::hashDb();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash);

        $this->exec('DROP TABLE t');
        $this->exec('CREATE TABLE t (apple varchar(10) NULL, zebra int NOT NULL)');

        self::assertSame($hash, self::hashDb());
    }

    public function testSchemaHashIgnoresTheOrderTablesAreCreatedIn(): void
    {
        $this->exec('CREATE TABLE zebra (id int NOT NULL)');
        $this->exec('CREATE TABLE apple (name varchar(10) NULL)');
        $hash = self::hashDb();

        $this->exec('DROP TABLE zebra');
        $this->exec('CREATE TABLE zebra (id int NOT NULL)');

        self::assertSame($hash, self::hashDb());
    }

    public function testSchemaHashChangesWithTheSchema(): void
    {
        $this->exec('CREATE TABLE t (id int NOT NULL)');
        $initialHash = self::hashDb();
        self::assertSame($initialHash, self::hashDb());

        $this->exec('ALTER TABLE t RENAME COLUMN id TO record_id');
        $renamedColumnHash = self::hashDb();
        self::assertNotSame($initialHash, $renamedColumnHash);

        $this->exec('ALTER TABLE t MODIFY record_id bigint NOT NULL');
        $changedTypeHash = self::hashDb();
        self::assertNotSame($renamedColumnHash, $changedTypeHash);

        $this->exec('ALTER TABLE t MODIFY record_id bigint NULL');
        $nullableHash = self::hashDb();
        self::assertNotSame($changedTypeHash, $nullableHash);

        $this->exec('CREATE TABLE t2 (id int NOT NULL)');
        self::assertNotSame($nullableHash, self::hashDb());
    }

    private function exec(string $statement): void
    {
        if (null === $this->connection) {
            self::fail('No connection.');
        }

        $this->connection->exec($statement);
    }

    /**
     * A fresh connection per hash: DDL of another connection is invisible within
     * the transaction the hasher runs in.
     */
    private static function hashDb(): string
    {
        return (new SchemaHasherMysql(self::createPdo(self::DATABASE_NAME)))->hashDb();
    }

    private static function createPdo(?string $database): PDO
    {
        $host = self::env('DBA_HOST', '127.0.0.1');
        $port = '';
        if (false !== strpos($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
            $port = ';port=' . $port;
        }

        $dsn = sprintf('mysql:host=%s', $host) . $port;
        if (null !== $database) {
            $dsn .= ';dbname=' . $database;
        }

        return new PDO(
            $dsn,
            self::env('DBA_USER', 'root'),
            self::env('DBA_PASSWORD', 'root'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        if (false === $value) {
            $value = $_ENV[$name] ?? $default;
        }

        return \is_string($value) ? $value : $default;
    }
}
