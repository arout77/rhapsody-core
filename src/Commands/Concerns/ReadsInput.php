<?php
namespace Rhapsody\Core\Commands\Concerns;

use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Typed accessors for console input.
 *
 * Symfony's InputInterface::getArgument()/getOption() return `mixed`
 * (string, string[], bool or null depending on how the argument was
 * declared). These narrow that to the type the command actually expects
 * and fail with a clear console error when it doesn't match.
 */
trait ReadsInput
{
    protected function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Argument \"{$name}\" must be a non-empty string.");
        }

        return $value;
    }
}
