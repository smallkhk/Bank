<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/**
 * File uploads for support tickets and chat. Stored outside the web root with random
 * names and served only through an authorization-checked controller.
 */
final class AttachmentService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const ALLOWED = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
    ];

    /** Returns attachment id, null when no file was sent. Throws BankingException on invalid files. */
    public static function fromUpload(string $field): ?int
    {
        $f = $_FILES[$field] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > self::MAX_BYTES || !is_uploaded_file($f['tmp_name'])) {
            throw new BankingException('The attachment could not be uploaded. Files must be under 5 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = self::ALLOWED[$mime] ?? null;
        if (!$ext) {
            throw new BankingException('Only PNG, JPG, WEBP, PDF or TXT attachments are allowed.');
        }
        $dir = STORAGE_PATH . '/attachments';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], "$dir/$stored")) {
            throw new BankingException('The attachment could not be saved.');
        }
        $name = preg_replace('/[^\w.\- ]+/u', '_', basename((string) $f['name'])) ?: 'file.' . $ext;
        return Db::insert('attachments', [
            'uploaded_by' => Auth::id(), 'original_name' => mb_substr($name, 0, 190),
            'stored_name' => $stored, 'mime' => $mime, 'size' => (int) $f['size'],
        ]);
    }

    public static function stream(array $att): never
    {
        $file = STORAGE_PATH . '/attachments/' . basename($att['stored_name']);
        if (!is_file($file)) {
            http_response_code(404);
            exit;
        }
        $inline = str_starts_with($att['mime'], 'image/') || $att['mime'] === 'application/pdf';
        header('Content-Type: ' . $att['mime']);
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $att['original_name']) . '"');
        readfile($file);
        exit;
    }
}
