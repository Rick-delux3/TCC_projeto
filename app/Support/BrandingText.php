<?php

namespace App\Support;

class BrandingText
{
    public static function choose(string $client, string $tcc): string
    {
        return config('branding.active') === 'tcc' ? $tcc : $client;
    }
}
