<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\ClickhouseService;
use Castor\Docker\Service\DumpableServiceInterface;
use Castor\Docker\Service\MySQLService;
use Castor\Docker\Service\PostgresService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function Castor\Docker\describe_dump_files;
use function Castor\Docker\dump_script;
use function Castor\Docker\find_dependents;
use function Castor\Docker\get_copy_file_name;
use function Castor\Docker\resolve_dump_file;
use function Castor\Docker\restore_script;

/**
 * The part of the dumps and restores that runs outside the database: naming
 * the file, compressing it, and recognising a dump by its first bytes.
 */
final class DatabaseDumpTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function provideDumpFiles(): iterable
    {
        yield 'plain SQL' => ['prod.sql', 'sql', null];
        yield 'compressed SQL' => ['prod.sql.zst', 'sql', 'zst'];
        yield 'upper case' => ['PROD.SQL.GZ', 'sql', 'gz'];
        yield 'a dot in the directory' => ['/tmp/v1.2/prod.sql.xz', 'sql', 'xz'];
        yield 'a dot in the name' => ['prod.2026-09-23.sql', 'sql', null];
        yield 'custom format' => ['prod.dump', 'dump', null];
    }

    #[DataProvider('provideDumpFiles')]
    public function testTheFileNameGivesTheFormatAndTheCompression(string $file, string $format, ?string $compression): void
    {
        static::assertSame(['format' => $format, 'compression' => $compression], resolve_dump_file($file, ['sql', 'dump']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnknownFiles(): iterable
    {
        yield 'no extension' => ['prod'];
        yield 'only a compression' => ['prod.gz'];
        yield 'a format the service does not write' => ['prod.zip'];
    }

    #[DataProvider('provideUnknownFiles')]
    public function testAFileNamedAfterNoFormatIsRefused(string $file): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('.sql, .sql.gz, .sql.zst, .sql.xz, .dump');

        resolve_dump_file($file, ['sql', 'dump']);
    }

    public function testOnlyTheTextFormatIsOfferedCompressed(): void
    {
        static::assertSame(['sql', 'sql.gz', 'sql.zst', 'sql.xz', 'dump'], describe_dump_files(['sql', 'dump']));
        static::assertSame(['zip'], describe_dump_files(['zip']));
    }

    /**
     * Both forms of "depends_on" compose accepts, and never the database
     * itself — ClickHouse depends on its own keeper.
     */
    public function testTheDependentsAreReadFromDependsOn(): void
    {
        $services = [
            'postgres' => [],
            'app' => ['depends_on' => ['postgres' => ['condition' => 'service_healthy']]],
            'app-worker-messenger' => ['depends_on' => ['postgres' => ['condition' => 'service_healthy'], 'mailpit' => []]],
            'legacy' => ['depends_on' => ['postgres']],
            'front' => ['depends_on' => ['app' => []]],
            'clickhouse' => ['depends_on' => ['clickhouse-keeper' => []]],
            'clickhouse-keeper' => [],
        ];

        static::assertSame(['app', 'app-worker-messenger', 'legacy'], find_dependents($services, ['postgres']));
        static::assertSame([], find_dependents($services, ['clickhouse', 'clickhouse-keeper']));
    }

    public function testACopyGoesThroughTheFormatRestoringFastest(): void
    {
        static::assertSame('postgres.dump', get_copy_file_name(new PostgresService()));
        static::assertSame('mysql.sql.zst', get_copy_file_name(new MySQLService()));
        static::assertSame('clickhouse.zip', get_copy_file_name(new ClickhouseService()));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideCompressions(): iterable
    {
        yield 'none' => [null];
        yield 'gzip' => ['gzip'];
        yield 'zstd' => ['zstd'];
        yield 'xz' => ['xz'];
    }

    /**
     * The compression is told from the first bytes, and those bytes — read
     * ahead to tell it — reach the database all the same.
     */
    #[DataProvider('provideCompressions')]
    public function testARestoreRecognisesTheCompressionOfTheDump(?string $compressor): void
    {
        $this->requireTools('bash', 'od', 'dd', ...(null === $compressor ? [] : [$compressor]));

        $dump = "-- a dump\nINSERT INTO t VALUES (1);\n" . str_repeat("INSERT INTO t VALUES (2);\n", 1000);
        $input = null === $compressor ? $dump : $this->runCommand([$compressor, '-c'], $dump);
        $received = tempnam(sys_get_temp_dir(), 'castor-restore-');

        $this->runCommand(['bash', '-c', restore_script(new FakeDumpableService())], $input, ['CASTOR_RECEIVED' => $received]);

        static::assertSame($dump, file_get_contents($received));

        unlink($received);
    }

    public function testAnEmptyDumpIsRefused(): void
    {
        $this->requireTools('bash', 'od', 'dd');

        $process = new Process(['bash', '-c', restore_script(new FakeDumpableService())]);
        $process->setInput('');
        $process->run();

        static::assertFalse($process->isSuccessful());
        static::assertStringContainsString('The dump is empty.', $process->getErrorOutput());
    }

    /**
     * A file only takes its name once complete, and belongs to the user.
     */
    public function testADumpIsWrittenToItsFileCompressed(): void
    {
        $this->requireTools('bash', 'zstd');

        $directory = sys_get_temp_dir() . '/castor-dump-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $owner = getmyuid() . ':' . getmygid();

        $this->runCommand(['bash', '-c', dump_script(new FakeDumpableService(), 'sql', 'zst')], '', [
            'CASTOR_OUTPUT' => $directory . '/app.sql.zst',
            'CASTOR_OWNER' => $owner,
        ]);

        static::assertSame(['app.sql.zst'], array_values(array_diff((array) scandir($directory), ['.', '..'])));
        static::assertSame("-- the dump\n", $this->runCommand(['zstd', '-dc', $directory . '/app.sql.zst']));

        unlink($directory . '/app.sql.zst');
        rmdir($directory);
    }

    public function testAFailedDumpLeavesNoFileBehind(): void
    {
        $this->requireTools('bash');

        $directory = sys_get_temp_dir() . '/castor-dump-' . bin2hex(random_bytes(4));
        mkdir($directory);

        $process = new Process(['bash', '-c', dump_script(new FakeDumpableService(fail: true), 'sql', null)], env: [
            'CASTOR_OUTPUT' => $directory . '/app.sql',
            'CASTOR_OWNER' => getmyuid() . ':' . getmygid(),
        ]);
        $process->run();

        static::assertFalse($process->isSuccessful());
        static::assertSame([], array_values(array_diff((array) scandir($directory), ['.', '..'])));

        rmdir($directory);
    }

    private function requireTools(string ...$tools): void
    {
        foreach ($tools as $tool) {
            if (null === (new ExecutableFinder())->find($tool)) {
                static::markTestSkipped(\sprintf('"%s" is not installed.', $tool));
            }
        }
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private function runCommand(array $command, string $input = '', array $env = []): string
    {
        $process = new Process($command, env: $env);
        $process->setInput($input);
        $process->mustRun();

        return $process->getOutput();
    }
}

/**
 * A database with nothing behind it: its restore writes what it received to
 * CASTOR_RECEIVED, and its dump prints a fixed line.
 */
final class FakeDumpableService implements DumpableServiceInterface
{
    public function __construct(
        private readonly bool $fail = false,
    ) {}

    public function getName(): string
    {
        return 'fake';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder;
    }

    public function getTasks(): iterable
    {
        return [];
    }

    public function getDumpFormats(): array
    {
        return ['sql'];
    }

    public function getConnectionScript(): string
    {
        return 'castor_ready() { true; }';
    }

    public function getDumpScript(string $format): string
    {
        return $this->fail
            ? 'castor_dump() { echo "-- half a dump"; return 1; }'
            : 'castor_dump() { echo "-- the dump"; }';
    }

    public function getRestoreScript(): string
    {
        return 'castor_restore() { cat > "$CASTOR_RECEIVED"; }';
    }

    public function getDumpEnvironment(): array
    {
        return [];
    }

    public function getDumpServices(): array
    {
        return ['fake'];
    }
}
