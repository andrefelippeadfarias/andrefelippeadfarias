<?php

declare(strict_types=1);

namespace Ritmo\Tests\Support;

use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * Prints the public surface of a class, interface, trait or enum as PHP signatures, with short
 * class names. docs/CONTRACTS.md is written from this output and a test keeps the two in step.
 */
final class SignaturePrinter
{
    /**
     * One line per enum case, public constant, public property and public method declared by
     * the type itself (inherited members are left out).
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    public static function lines(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $lines = [];

        if ($reflection->isEnum()) {
            foreach ((new ReflectionEnum($class))->getCases() as $case) {
                $lines[] = sprintf('case %s = %s;', $case->getName(), var_export($case->getBackingValue(), true));
            }
        }
        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->isPublic() && !$constant->isEnumCase() && $constant->getDeclaringClass()->getName() === $class) {
                $lines[] = sprintf('const %s = %s;', $constant->getName(), self::export($constant->getValue()));
            }
        }
        foreach ($reflection->getProperties() as $property) {
            if ($property->isPublic() && $property->getDeclaringClass()->getName() === $class && !$reflection->isEnum()) {
                $lines[] = sprintf('public readonly %s $%s;', self::type($property->getType()), $property->getName());
            }
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $class && !self::isEnumBuiltIn($reflection, $method)) {
                $lines[] = self::method($method);
            }
        }

        return $lines;
    }

    public static function method(ReflectionMethod $method): string
    {
        $parameters = array_map(self::parameter(...), $method->getParameters());
        $return = $method->hasReturnType() ? ': ' . self::type($method->getReturnType()) : '';

        return sprintf('public %sfunction %s(%s)%s;', $method->isStatic() ? 'static ' : '', $method->getName(), implode(', ', $parameters), $return);
    }

    private static function parameter(ReflectionParameter $parameter): string
    {
        $text = ($parameter->hasType() ? self::type($parameter->getType()) . ' ' : '') . '$' . $parameter->getName();
        if ($parameter->isDefaultValueAvailable()) {
            $text .= ' = ' . self::export($parameter->getDefaultValue());
        }

        return $text;
    }

    private static function type(?ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = self::short($type->getName());

            return $type->allowsNull() && !in_array($name, ['mixed', 'null'], true) ? '?' . $name : $name;
        }
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(static fn (ReflectionNamedType $part): string => self::short($part->getName()), $type->getTypes()));
        }

        return 'mixed';
    }

    private static function export(mixed $value): string
    {
        if ($value instanceof UnitEnum) {
            return self::short($value::class) . '::' . $value->name;
        }
        if ($value === []) {
            return '[]';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return '[' . implode(', ', array_map(self::export(...), $value)) . ']';
        }

        return var_export($value, true);
    }

    private static function short(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private static function isEnumBuiltIn(ReflectionClass $class, ReflectionMethod $method): bool
    {
        return $class->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true);
    }
}
