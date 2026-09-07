<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Exception\InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The arguments and options of one command, declared once ({@see Definitions}) and rendered for each framework:
 * {@see applyTo()} for a symfony/console command (the bundle, Yii3 and, since wave M, artisan register the command
 * classes of this package), {@see yiiOptions()} / {@see yiiAliases()} for a Yii2 console controller. Every framework
 * then prints the same names, shortcuts, defaults and descriptions.
 */
final readonly class CommandDefinition
{
    /**
     * @param list<ArgumentDefinition> $arguments
     * @param list<OptionDefinition>   $options
     */
    public function __construct(
        public string $description,
        public array $arguments = [],
        public array $options = [],
    ) {}

    /**
     * @throws InvalidArgumentException for a name the definition has not
     */
    public function argument(string $name): ArgumentDefinition
    {
        foreach ($this->arguments as $argument) {
            if ($argument->name === $name) {
                return $argument;
            }
        }

        throw new InvalidArgumentException(\sprintf('The command has no argument "%s"; it has: %s.', $name, implode(', ', array_map(static fn(ArgumentDefinition $a): string => $a->name, $this->arguments))));
    }

    /**
     * @throws InvalidArgumentException for a name the definition has not
     */
    public function option(string $name): OptionDefinition
    {
        foreach ($this->options as $option) {
            if ($option->name === $name) {
                return $option;
            }
        }

        throw new InvalidArgumentException(\sprintf('The command has no option "%s"; it has: %s.', $name, implode(', ', array_map(static fn(OptionDefinition $o): string => $o->name, $this->options))));
    }

    /** symfony/console: `configure()` is `Definitions::check()->applyTo($this)`. */
    public function applyTo(Command $command): void
    {
        $command->setDescription($this->description);
        foreach ($this->arguments as $argument) {
            $mode = ($argument->required ? InputArgument::REQUIRED : InputArgument::OPTIONAL) | ($argument->array ? InputArgument::IS_ARRAY : 0);
            $command->addArgument($argument->name, $mode, $argument->description);
        }
        foreach ($this->options as $option) {
            [$mode, $default] = match ($option->mode) {
                OptionDefinition::FLAG => [InputOption::VALUE_NONE, null],
                OptionDefinition::VALUE => [InputOption::VALUE_REQUIRED, $option->default],
                OptionDefinition::LIST => [InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, []],
                default => [InputOption::VALUE_OPTIONAL, false],
            };
            $command->addOption($option->name, $option->shortcut, $mode, $option->description, $default);
        }
    }

    /**
     * Yii: the option names of `Controller::options()`, as the camelCase public properties of the controller. A LIST
     * option is an `array` property: Yii splits `--host=a,b` on the comma into it.
     *
     * @return list<string>
     */
    public function yiiOptions(): array
    {
        return array_map(static fn(OptionDefinition $o): string => $o->property(), $this->options);
    }

    /**
     * Yii: the shortcuts of `Controller::optionAliases()`, `['f' => 'force']`.
     *
     * @return array<string, string>
     */
    public function yiiAliases(): array
    {
        $aliases = [];
        foreach ($this->options as $option) {
            if ($option->shortcut !== null) {
                $aliases[$option->shortcut] = $option->property();
            }
        }

        return $aliases;
    }
}
