<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * The command line of "docker:service:install", where every input of an
 * installer is also an option: "--with-version=11.4" answers the question the
 * install would otherwise ask, so a service installs without any interaction.
 *
 * Options are prefixed because castor answers some of these names itself —
 * "--version" prints its own version before a task ever runs — and because the
 * prefix reads like the "withVersion()" of the service the input configures.
 */
final class InstallerOptions
{
    /** Prefix every input option carries, e.g. "package_manager" is "--with-package-manager". */
    public const PREFIX = 'with-';

    /** The database an application links to, which is no input of its own. */
    public const DATABASE = self::PREFIX . 'database';

    /**
     * @param array<string, mixed> $answers  the inputs answered on the command line, keyed by input name
     * @param string|null          $database the "--with-database" value, for an installer linking to one
     * @param string|null          $file     the "--file" value, wherever it sits on the command line
     */
    private function __construct(
        public readonly array $answers,
        public readonly ?string $database,
        public readonly ?string $file,
    ) {}

    /**
     * @param list<string>      $tokens             the raw tokens of the command line, the service name included
     * @param list<InputOption> $applicationOptions the global options that may sit among them ("-n", "-v"…)
     */
    public static function parse(ServiceInstaller $installer, array $tokens, array $applicationOptions = []): self
    {
        $definition = self::createDefinition($installer, $applicationOptions);

        try {
            $parsed = new ArgvInput(['castor', ...$tokens], $definition);
        } catch (ConsoleException $e) {
            // Not chained: the console exception says the same thing, and both
            // would be rendered, one under the other.
            throw new \RuntimeException(\sprintf('%s %s', $e->getMessage(), self::describe($installer)));
        }

        $answers = [];

        foreach ($installer->getInputs() as $input) {
            $value = $parsed->getOption(self::optionName($input));

            // Absent options keep their null default — the empty array for a
            // multiple choice, which repeats — so the question is asked (or its
            // default taken) as if nothing had been passed.
            if ($value === null || $value === []) {
                continue;
            }

            $answers[$input->name] = self::cast($input, $value);
        }

        return new self(
            $answers,
            $installer instanceof NeedsDatabase ? self::string($parsed->getOption(self::DATABASE)) : null,
            self::string($parsed->getOption('file')),
        );
    }

    /**
     * Whether the token following an option on the command line is its value,
     * and so names no service. Castor's own options say it themselves; an
     * install option says it through the input it answers, which holds no value
     * when that input is a boolean. Anything else is taken to hold one.
     *
     * @param array<string, ServiceInstaller> $installers
     * @param list<InputOption>               $applicationOptions
     */
    public static function expectsValue(string $token, array $installers, array $applicationOptions = []): bool
    {
        if (str_contains($token, '=')) {
            return false;
        }

        $definition = new InputDefinition($applicationOptions);

        if (!str_starts_with($token, '--')) {
            // Only the last shortcut of a bundle ("-nv") may take a value.
            $shortcut = substr($token, -1);

            return !$definition->hasShortcut($shortcut) || $definition->getOptionForShortcut($shortcut)->acceptValue();
        }

        $name = substr($token, 2);

        if ($definition->hasOption($name)) {
            return $definition->getOption($name)->acceptValue();
        }

        return !self::isFlag($name, $installers);
    }

    /**
     * The option answering an input, e.g. "with-package-manager".
     */
    public static function optionName(Input $input): string
    {
        return self::PREFIX . str_replace('_', '-', $input->name);
    }

    /**
     * How every input of an installer is written on the command line, one entry
     * per option.
     *
     * @return list<string>
     */
    public static function usage(ServiceInstaller $installer): array
    {
        $usage = [];

        foreach ($installer->getInputs() as $input) {
            $name = '--' . self::optionName($input);

            $usage[] = match ($input->type) {
                InputType::Boolean => $name,
                // A multiple choice repeats, which the trailing "..." says the
                // way every other command line does.
                InputType::Choice => $name . '=' . implode('|', $input->choices) . ($input->multiple ? '...' : ''),
                InputType::Integer, InputType::Text => $name . '=' . strtoupper(str_replace('_', '-', $input->name)),
            };
        }

        if ($installer instanceof NeedsDatabase) {
            $usage[] = '--' . self::DATABASE . '=DATABASE';
        }

        return $usage;
    }

    /**
     * Whether an install option answers a boolean input — "--with-force" and
     * the "--no-with-force" negating it, neither of which takes a value. A name
     * no installer declares is none of their business, and left to the parsing
     * to reject.
     *
     * @param array<string, ServiceInstaller> $installers
     */
    private static function isFlag(string $name, array $installers): bool
    {
        $name = str_starts_with($name, 'no-') ? substr($name, 3) : $name;

        if (!str_starts_with($name, self::PREFIX)) {
            return false;
        }

        foreach ($installers as $installer) {
            foreach ($installer->getInputs() as $input) {
                if (self::optionName($input) === $name) {
                    return $input->type === InputType::Boolean;
                }
            }
        }

        return false;
    }

    /**
     * @param list<InputOption> $applicationOptions
     */
    private static function createDefinition(ServiceInstaller $installer, array $applicationOptions): InputDefinition
    {
        // The global options are part of the definition so that a "-n" or a
        // "-v" sitting among the installer ones parses instead of blowing up.
        $definition = new InputDefinition($applicationOptions);

        $definition->addArgument(new InputArgument('name', InputArgument::OPTIONAL, 'The service to install'));
        $definition->addOption(new InputOption('file', null, InputOption::VALUE_REQUIRED, 'The file holding the RegisterServiceEvent listener'));

        if ($installer instanceof NeedsDatabase) {
            $definition->addOption(new InputOption(self::DATABASE, null, InputOption::VALUE_REQUIRED, 'The database to link the application to, "none" for no database, or a database to install'));
        }

        foreach ($installer->getInputs() as $input) {
            $definition->addOption(self::createOption($input));
        }

        return $definition;
    }

    private static function createOption(Input $input): InputOption
    {
        // A boolean is a flag and its negation ("--with-x", "--no-with-x"), so
        // that not passing it stays distinguishable from passing it false. A
        // multiple choice repeats instead, one occurrence per choice picked.
        $mode = match (true) {
            $input->type === InputType::Boolean => InputOption::VALUE_NONE | InputOption::VALUE_NEGATABLE,
            $input->multiple => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            default => InputOption::VALUE_REQUIRED,
        };

        return new InputOption(
            self::optionName($input),
            null,
            $mode,
            $input->label,
            null,
            $input->type === InputType::Choice ? $input->choices : [],
        );
    }

    private static function string(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    private static function cast(Input $input, mixed $value): mixed
    {
        if ($input->type === InputType::Choice && $input->multiple) {
            return self::castChoices($input, \is_array($value) ? array_values($value) : [$value]);
        }

        $string = self::string($value) ?? '';

        return match ($input->type) {
            InputType::Boolean => (bool) $value,
            InputType::Text => $string,
            InputType::Integer => preg_match('/^-?\d+$/', $string)
                ? (int) $string
                : throw new \RuntimeException(\sprintf('The "--%s" option expects a number, got "%s".', self::optionName($input), $string)),
            InputType::Choice => self::choice($input, $string),
        };
    }

    /**
     * The choices a multiple input was answered with, written as one occurrence
     * each or comma-separated in one — the prompt reads a comma-separated list
     * too, so no choice may hold a comma anyway.
     *
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function castChoices(Input $input, array $values): array
    {
        $choices = [];

        foreach ($values as $value) {
            foreach (explode(',', self::string($value) ?? '') as $choice) {
                // "--with-x=" picks nothing, the way an empty value answers
                // every other option.
                if ($choice === '') {
                    continue;
                }

                $choices[] = self::choice($input, $choice);
            }
        }

        return array_values(array_unique($choices));
    }

    private static function choice(Input $input, string $value): string
    {
        return \in_array($value, $input->choices, true)
            ? $value
            : throw new \RuntimeException(\sprintf('The "--%s" option expects one of "%s", got "%s".', self::optionName($input), implode('", "', $input->choices), $value));
    }

    private static function describe(ServiceInstaller $installer): string
    {
        $usage = self::usage($installer);

        if ($usage === []) {
            return \sprintf('"%s" takes no install option.', $installer->getName());
        }

        return \sprintf('Options of "%s": %s.', $installer->getName(), implode(' ', $usage));
    }
}
