<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\import;
use function Castor\PHPQa\phpstan;
use function Castor\PHPQa\php_cs_fixer;

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', false);

import(__DIR__ . '/tools/doc/castor.php');

#[AsTask(description: 'Run PHPStan', namespace: 'qa', name: 'phpstan', aliases: ['phpstan'])]
function qa_phpstan(): int
{
    return phpstan(arguments: ['analyze', '--memory-limit=1G'], version: '2.1.32')->getExitCode();
}

#[AsTask(description: 'Fix CS', namespace: 'qa', name: 'cs', aliases: ['cs'])]
function qa_phpcsfixer(bool $dryRun = false): int
{
    $args = null;

    if ($dryRun) {
        $args = ['fix', '--dry-run'];
    }

    return php_cs_fixer(arguments: $args, version: '3.92.4')->getExitCode();
}
