<?php

namespace App\Enums;

enum Weekday: string
{
    case MONDAY = 'monday';
    case TUESDAY = 'tuesday';
    case WEDNESDAY = 'wednesday';
    case THURSDAY = 'thursday';
    case FRIDAY = 'friday';
    case SATURDAY = 'saturday';
    case SUNDAY = 'sunday';

    public function toSpanish(): string
    {
        return match ($this) {
            self::MONDAY => 'Lunes',
            self::TUESDAY => 'Martes',
            self::WEDNESDAY => 'Miércoles',
            self::THURSDAY => 'Jueves',
            self::FRIDAY => 'Viernes',
            self::SATURDAY => 'Sábado',
            self::SUNDAY => 'Domingo',
        };
    }

     /**
     * Obtener el valor en inglés a partir del nombre en español.
     */
    public static function fromSpanish(string $spanishName): ?self
    {
        // Normalizar el nombre en español (eliminar tildes y convertir a minúsculas)
        $spanishName = self::normalizeString($spanishName);

        // Crear un array de nombres en español normalizados
        $spanishNamesMap = array_map(
            fn($case) => self::normalizeString($case->toSpanish()),
            self::cases()
        );

        // Buscar el índice del nombre en español normalizado
        $index = array_search($spanishName, $spanishNamesMap);

        // Si se encuentra, devolver el caso correspondiente
        return $index !== false ? self::cases()[$index] : null;
    }

    /**
     * Normalizar un string (eliminar tildes y convertir a minúsculas).
     */
    private static function normalizeString(string $string): string
    {
        // Convertir a minúsculas
        $string = mb_strtolower($string, 'UTF-8');

        // Eliminar tildes
        $string = str_replace(
            ['á', 'é', 'í', 'ó', 'ú'],
            ['a', 'e', 'i', 'o', 'u'],
            $string
        );

        return $string;
    }
}