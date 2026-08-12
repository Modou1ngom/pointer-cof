<?php

namespace App\Support;

final class SqlSafe
{
    /**
     * Valeur pour un LIKE « contient », sans injection de jokers % / _.
     */
    public static function likeContains(string $value): string
    {
        $clean = str_replace(['\\', '%', '_'], '', $value);

        return '%'.$clean.'%';
    }
}
