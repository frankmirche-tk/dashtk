<?php

declare(strict_types=1);

namespace App\Tracing;

final class TraceContext
{
    private static ?Trace $current = null;

    public static function set(?Trace $trace): void
    {
        self::$current = $trace;
    }

    public static function get(): ?Trace
    {
        return self::$current;
    }

    public static function span(string $name, callable $fn, array $meta = [])
    {
        $t = self::$current;

        if ($t instanceof Trace) {
            return $t->span($name, $fn, $meta);
        }

        return $fn();
    }
}
