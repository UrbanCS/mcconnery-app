<?php

declare(strict_types=1);

function normalize_french_date(string $value): string
{
    $value = trim($value);
    $map = [
        'lun,' => 'Mon,',
        'mar,' => 'Tue,',
        'mer,' => 'Wed,',
        'jeu,' => 'Thu,',
        'ven,' => 'Fri,',
        'sam,' => 'Sat,',
        'dim,' => 'Sun,',
        'janvier' => 'January',
        'jan' => 'Jan',
        'fevrier' => 'February',
        'fev' => 'Feb',
        'fév' => 'Feb',
        'février' => 'February',
        'mars' => 'March',
        'avr' => 'Apr',
        'avril' => 'April',
        'mai' => 'May',
        'juin' => 'June',
        'juil' => 'Jul',
        'juillet' => 'July',
        'aout' => 'August',
        'août' => 'August',
        'septembre' => 'September',
        'sep' => 'Sep',
        'octobre' => 'October',
        'oct' => 'Oct',
        'novembre' => 'November',
        'nov' => 'Nov',
        'decembre' => 'December',
        'décembre' => 'December',
        'dec' => 'Dec',
        'déc' => 'Dec',
    ];

    return strtr($value, $map);
}

function parse_date_or_null(string $value, bool $dateOnly = false): ?string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, '-0001')) {
        return null;
    }

    $timestamp = strtotime(normalize_french_date($value));
    if ($timestamp === false) {
        return null;
    }

    return $dateOnly ? date('Y-m-d', $timestamp) : date('Y-m-d H:i:s', $timestamp);
}
