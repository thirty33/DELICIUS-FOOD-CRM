<?php

namespace App\Classes;

use Carbon\Carbon;

class DateTimeHelper
{

    /**
     * Formatea la fecha y hora en un texto legible, teniendo en cuenta los días de preparación.
     *
     * @param Carbon $dateTime Fecha y hora máxima para hacer el pedido.
     * @param int $preparationDays Número de días de preparación.
     * @param Carbon $publicationDate Fecha de publicación del menú.
     * @return string
     */
    public static function formatMaximumOrderTime(Carbon $dateTime, int $preparationDays, Carbon $publicationDate): string
    {
        // Restar los días de preparación de la fecha de publicación
        $orderDate = $publicationDate->copy()->subDays($preparationDays);

        // Formatear la fecha en un formato legible (ejemplo: "Lunes 27 de enero de 2025")
        $formattedDate = $orderDate->isoFormat('dddd D [de] MMMM [de] YYYY');

        // Formatear la hora en formato militar (ejemplo: "12:00")
        $formattedTime = $dateTime->format('H:i');

        // Combinar todo en un texto legible
        return "Disponible hasta el {$formattedDate} a las {$formattedTime}";
    }

    public static function formatDateReadable($date): string
    {
        // Convertir la fecha a un objeto Carbon si es una cadena
        if (!($date instanceof Carbon)) {
            $date = Carbon::parse($date);
        }

        // Formatear la fecha en el formato deseado
        return $date->isoFormat('dddd D [de] MMMM [de] YYYY');
    }
    
}
