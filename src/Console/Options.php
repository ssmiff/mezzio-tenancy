<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Console;

use Symfony\Component\Console\Input\InputInterface;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

final readonly class Options
{
    public static function string(InputInterface $input, string $name): ?string
    {
        return $input->hasOption($name) ? self::asString($input->getOption($name)) : null;
    }

    public static function argument(InputInterface $input, string $name): ?string
    {
        return self::asString($input->getArgument($name));
    }

    /** @return list<string> */
    public static function strings(InputInterface $input, string $name): array
    {
        return self::asStrings($input->getOption($name));
    }

    private static function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    private static function asStrings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
