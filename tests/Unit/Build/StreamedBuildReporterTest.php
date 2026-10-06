<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Build;

use Castor\Docker\Build\StreamedBuildReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class StreamedBuildReporterTest extends TestCase
{
    public function testEveryLineIsPrefixedByItsService(): void
    {
        $output = new BufferedOutput();
        $reporter = new StreamedBuildReporter($output, ['app', 'app-builder']);

        $reporter->line('app', '#5 [frontend 2/8] RUN apt-get update');
        $reporter->line('app-builder', '#4 <not a tag>');

        static::assertSame(
            "app         | #5 [frontend 2/8] RUN apt-get update\napp-builder | #4 <not a tag>\n",
            $output->fetch(),
        );
    }
}
