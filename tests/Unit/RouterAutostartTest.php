<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\is_router_autostart_enabled;

/**
 * The router is started by the first project that needs it and stopped with the
 * last one, unless the project or the environment says otherwise.
 */
final class RouterAutostartTest extends TestCase
{
    private const VARIABLE = 'CASTOR_DOCKER_ROUTER_AUTOSTART';

    private mixed $backup = null;

    protected function setUp(): void
    {
        $this->backup = $_SERVER[self::VARIABLE] ?? null;

        unset($_SERVER[self::VARIABLE]);
    }

    protected function tearDown(): void
    {
        if (null === $this->backup) {
            unset($_SERVER[self::VARIABLE]);

            return;
        }

        $_SERVER[self::VARIABLE] = $this->backup;
    }

    private function context(mixed $autostart): Context
    {
        /** @var array{router_autostart?: bool} $data */
        $data = null === $autostart ? [] : ['router_autostart' => $autostart];

        return new Context($data);
    }

    /**
     * A project routing a domain cannot be reached without the router, so it is
     * started for you: nothing to remember, and no "connection refused" to
     * diagnose.
     */
    public function testItIsOnByDefault(): void
    {
        static::assertTrue(is_router_autostart_enabled($this->context(null)));
    }

    public function testTheContextTurnsItOff(): void
    {
        static::assertFalse(is_router_autostart_enabled($this->context(false)));
        static::assertTrue(is_router_autostart_enabled($this->context(true)));
    }

    /**
     * The escape hatch of a CI job or of a single shell, which cannot edit the
     * project's context.
     */
    public function testTheEnvironmentVariableWinsOverTheContext(): void
    {
        $_SERVER[self::VARIABLE] = '0';
        static::assertFalse(is_router_autostart_enabled($this->context(true)));

        $_SERVER[self::VARIABLE] = '1';
        static::assertTrue(is_router_autostart_enabled($this->context(false)));
    }

    /**
     * Whatever the Docker CLI reads as a boolean is read as one here too.
     */
    public function testTheUsualBooleanSpellingsAreUnderstood(): void
    {
        foreach (['false', 'off', 'no', 'FALSE'] as $off) {
            $_SERVER[self::VARIABLE] = $off;
            static::assertFalse(is_router_autostart_enabled($this->context(null)), $off);
        }

        foreach (['true', 'on', 'yes', 'TRUE'] as $on) {
            $_SERVER[self::VARIABLE] = $on;
            static::assertTrue(is_router_autostart_enabled($this->context(null)), $on);
        }
    }

    /**
     * An empty or nonsensical value is no instruction at all: it must not read
     * as "off" and silently leave a project unreachable.
     */
    public function testAnUnreadableValueFallsBackToTheContext(): void
    {
        foreach (['', 'maybe'] as $value) {
            $_SERVER[self::VARIABLE] = $value;

            static::assertTrue(is_router_autostart_enabled($this->context(null)), $value);
            static::assertFalse(is_router_autostart_enabled($this->context(false)), $value);
        }
    }

}
