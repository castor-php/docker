<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

enum InputType
{
    case Text;
    case Integer;
    case Boolean;
    case Choice;
}
