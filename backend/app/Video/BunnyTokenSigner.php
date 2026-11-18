<?php

namespace App\Video;

/**
 * Bunny CDN "Advanced" token authentication (HMAC-SHA256), directory form.
 * Mirrors BunnyWay/BunnyCDN.TokenAuthentication php/url_signing.php (no IP lock, no geo):
 *
 *   message = signature_path . expires . "token_path=" . path
 *   token   = "HS256-" . base64url_nopad( HMAC_SHA256(message, security_key, raw) )
 *   url     = https://host/bcdn_token={token}&token_path={rawurlencode(path)}&expires={expires}{file_path}
 *
 * The token lives in the path, so relative HLS segment URLs inherit it (required for HLS per Bunny docs).
 */
final class BunnyTokenSigner
{
    public function __construct(private readonly string $host, private readonly string $securityKey) {}

    /** @param string $directory e.g. "/{guid}/"  @param string $file e.g. "/{guid}/playlist.m3u8" */
    public function signDirectory(string $directory, string $file, int $expires): string
    {
        $token = $this->token($directory, $expires);

        return sprintf(
            'https://%s/bcdn_token=%s&token_path=%s&expires=%d%s',
            $this->host, $token, rawurlencode($directory), $expires, $file,
        );
    }

    public function token(string $directory, int $expires): string
    {
        $message = $directory.$expires.'token_path='.$directory;
        $digest = hash_hmac('sha256', $message, $this->securityKey, true);

        return 'HS256-'.rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }
}
