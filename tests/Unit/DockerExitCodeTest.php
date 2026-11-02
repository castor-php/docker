<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * docker_exit_code() repeats the arguments of docker_compose_run() to keep them
 * typed: one added to docker_compose_run() and not forwarded would silently do
 * nothing for whoever only wants the exit code.
 */
final class DockerExitCodeTest extends TestCase
{
    public function testItTakesTheArgumentsOfDockerComposeRun(): void
    {
        static::assertSame(
            $this->describe(new \ReflectionFunction('Castor\Docker\docker_compose_run')),
            $this->describe(new \ReflectionFunction('Castor\Docker\docker_exit_code')),
        );
    }

    /**
     * The default of "service" is left out: docker_exit_code() runs in the
     * builder when told nothing, docker_compose_run() wants to be told.
     *
     * @return list<string>
     */
    private function describe(\ReflectionFunction $function): array
    {
        $parameters = [];

        foreach ($function->getParameters() as $parameter) {
            $description = $parameter->getType() . ' $' . $parameter->getName();

            if ('service' !== $parameter->getName() && $parameter->isDefaultValueAvailable()) {
                $description .= ' = ' . var_export($parameter->getDefaultValue(), true);
            }

            $parameters[] = $description;
        }

        return $parameters;
    }
}
