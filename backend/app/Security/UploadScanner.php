<?php

namespace App\Security;

use RuntimeException;

/**
 * Malware scan hook for user uploads. Driver "clamav" streams the file to clamd (INSTREAM over
 * its Unix socket); "none" accepts everything (local dev / when a CDN-side scanner is used).
 */
class UploadScanner
{
    /** @return true|string true when clean, otherwise the signature name */
    public function scan(string $path): true|string
    {
        if (config('security.upload_scan.driver') !== 'clamav') {
            return true;
        }

        $socket = @stream_socket_client('unix://'.config('security.upload_scan.clamav_socket'), $errno, $error, 5);
        if (! $socket) {
            throw new RuntimeException("Virus scanner unavailable: {$error}");
        }

        try {
            stream_set_timeout($socket, 30);
            fwrite($socket, "zINSTREAM\0");
            $file = fopen($path, 'rb');
            if (! $file) {
                throw new RuntimeException('Upload not readable');
            }
            while (! feof($file)) {
                $chunk = (string) fread($file, 8192);
                if ($chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fclose($file);
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\n ");
        } finally {
            fclose($socket);
        }

        // "stream: OK" or "stream: Eicar-Test-Signature FOUND"
        if (str_ends_with($reply, 'OK')) {
            return true;
        }
        if (str_ends_with($reply, 'FOUND')) {
            return trim(str_replace(['stream:', 'FOUND'], '', $reply));
        }

        throw new RuntimeException('Unexpected scanner reply: '.$reply);
    }
}
