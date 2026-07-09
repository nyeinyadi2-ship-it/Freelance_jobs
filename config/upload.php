<?php

function upload_image(array $file, int $max_size = 2 * 1024 * 1024): ?string
{
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if ($file['size'] > $max_size) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        return null;
    }

    // Validate MIME type (skip if temp file is gone or fileinfo extension missing)
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime = null;
    $tmp = $file['tmp_name'];
    if ($tmp !== '' && file_exists($tmp)) {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if (($mime === null || $mime === false) && function_exists('mime_content_type')) {
            $mime = @mime_content_type($tmp);
        }
        if ($mime !== null && $mime !== false && !in_array($mime, $allowed_mimes, true)) {
            return null;
        }
    }

    // Ensure uploads directory exists
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            return null;
        }
    }

    $filename = uniqid('img_', true) . '.' . $ext;
    $dest = $upload_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $filename;
    }

    return null;
}

function delete_upload(string $filename): void
{
    if ($filename === '') {
        return;
    }
    $path = __DIR__ . '/../uploads/' . $filename;
    if (file_exists($path)) {
        unlink($path);
    }
}
