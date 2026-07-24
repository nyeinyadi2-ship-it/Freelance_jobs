<?php

// Simple translation function - returns the key as fallback
function __(string $key, array $replace = []): string
{
    $text = $key;
    foreach ($replace as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }
    return $text;
}

function translate_status(string $status): string
{
    return ucfirst($status);
}

function lang_switch_url(string $lang): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $parsed = parse_url($uri);
    $path = $parsed['path'] ?? '/';
    $query = [];

    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $query);
    }

    $query['lang'] = $lang;

    return $path . '?' . http_build_query($query);
}
