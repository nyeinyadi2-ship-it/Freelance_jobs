<?php

function init_lang(): void
{
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'my'], true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }

    if (empty($_SESSION['lang'])) {
        $_SESSION['lang'] = 'en';
    }
}

function current_lang(): string
{
    return $_SESSION['lang'] ?? 'en';
}

function load_translations(): array
{
    static $translations = null;

    if ($translations === null) {
        $file = __DIR__ . '/../lang/' . current_lang() . '.php';
        if (!file_exists($file)) {
            $file = __DIR__ . '/../lang/en.php';
        }
        $translations = require $file;
    }

    return $translations;
}

function __(string $key, array $replace = []): string
{
    $translations = load_translations();
    $text = $translations[$key] ?? $key;

    foreach ($replace as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }

    return $text;
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

function translate_status(string $status): string
{
    $key = 'status.' . $status;

    return load_translations()[$key] ?? ucfirst($status);
}
