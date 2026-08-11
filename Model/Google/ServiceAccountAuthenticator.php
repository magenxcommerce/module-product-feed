<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Google;

use Magenx\ProductFeed\Model\Export\FeedFilesystem;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Mints a Google access token from a service-account key, by the OAuth 2.0
 * JWT-bearer flow.
 *
 * WHY NO SDK: this is the only thing google/auth would be used for here, and the
 * flow is a signed JWT plus one POST. Requiring the Google client library - which
 * pulls in a dozen transitive packages - so a store can upload a CSV is a poor
 * trade, and an optional dependency that half the feature silently needs is worse
 * than no dependency at all. The Merchant API's REST surface is public and
 * versioned, so nothing here depends on a generated client staying current.
 *
 * THE KEY FILE LIVES IN var/, NEVER pub/media. It grants programmatic access to
 * the merchant's Merchant Center account; pub/media is web-served. FeedFilesystem
 * resolves it and basename()s the configured value so a traversal cannot escape.
 */
class ServiceAccountAuthenticator
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** The Merchant API is covered by the long-standing Content API scope. */
    private const SCOPE = 'https://www.googleapis.com/auth/content';

    private const TOKEN_LIFETIME = 3600;

    /**
     * Renew slightly early so a token cannot expire in flight between being
     * checked and being used.
     */
    private const EXPIRY_SKEW = 60;

    /** @var array<string, array{token: string, expires: int}> */
    private array $tokenCache = [];

    public function __construct(
        private readonly FeedFilesystem $feedFilesystem,
        private readonly FileDriver $fileDriver,
        private readonly Curl $curl
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function getAccessToken(string $keyFileName): string
    {
        // phpcs:ignore Magento2.Security.InsecureFunction.FoundWithAlternative -- cache key derived from a key file name, not a security digest.
        $cacheKey = md5($keyFileName);
        $cached = $this->tokenCache[$cacheKey] ?? null;

        if ($cached !== null && $cached['expires'] > time() + self::EXPIRY_SKEW) {
            return $cached['token'];
        }

        $credentials = $this->readKeyFile($keyFileName);
        $assertion = $this->buildAssertion($credentials);
        $token = $this->exchange($assertion);

        $this->tokenCache[$cacheKey] = [
            'token' => $token,
            'expires' => time() + self::TOKEN_LIFETIME,
        ];

        return $token;
    }

    /**
     * The service account's own address. It has to be added as a user on the
     * Merchant Center account, and naming it in an error message is the difference
     * between a five-minute fix and a support ticket.
     *
     * @throws LocalizedException
     */
    public function getClientEmail(string $keyFileName): string
    {
        return (string) ($this->readKeyFile($keyFileName)['client_email'] ?? '');
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function readKeyFile(string $keyFileName): array
    {
        $path = $this->feedFilesystem->getGoogleKeyPath($keyFileName);

        if ($path === null) {
            throw new LocalizedException(
                __(
                    'The Google service account key "%1" was not found in var/%2/. '
                    . 'Upload the JSON key there and set its file name in '
                    . 'Stores > Configuration > Magenx > Product Feeds > Google Merchant Center.',
                    $keyFileName,
                    FeedFilesystem::GMC_KEY_DIR
                )
            );
        }

        try {
            $raw = $this->fileDriver->fileGetContents($path);
        } catch (\Throwable $e) {
            throw new LocalizedException(__('The Google service account key could not be read.'), $e);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new LocalizedException(__('The Google service account key is not valid JSON.'));
        }

        foreach (['client_email', 'private_key'] as $required) {
            if (empty($decoded[$required])) {
                throw new LocalizedException(
                    __('The Google service account key is missing "%1". Download a fresh JSON key.', $required)
                );
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $credentials
     * @throws LocalizedException
     */
    private function buildAssertion(array $credentials): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_ENDPOINT,
            'exp' => $now + self::TOKEN_LIFETIME,
            'iat' => $now,
        ];

        $signingInput = $this->base64Url((string) json_encode($header))
            . '.' . $this->base64Url((string) json_encode($claims));

        $privateKey = openssl_pkey_get_private((string) $credentials['private_key']);
        if ($privateKey === false) {
            throw new LocalizedException(
                __('The private key in the Google service account file could not be read.')
            );
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new LocalizedException(__('The Google authentication assertion could not be signed.'));
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    /**
     * @throws LocalizedException
     */
    private function exchange(string $assertion): string
    {
        $this->curl->setTimeout(30);
        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');

        try {
            $this->curl->post(self::TOKEN_ENDPOINT, [
                'grant_type' => self::GRANT_TYPE,
                'assertion' => $assertion,
            ]);
        } catch (\Throwable $e) {
            throw new LocalizedException(__('Could not reach Google to authenticate: %1', $e->getMessage()), $e);
        }

        $body = json_decode((string) $this->curl->getBody(), true);

        if ($this->curl->getStatus() !== 200 || !is_array($body) || empty($body['access_token'])) {
            $reason = is_array($body)
                ? (string) ($body['error_description'] ?? $body['error'] ?? 'unknown error')
                : 'unreadable response';

            // A clock more than a few minutes out makes every assertion invalid, and
            // the raw message ("Invalid JWT: Token must be a short-lived token")
            // does not point anywhere useful on its own.
            throw new LocalizedException(
                __(
                    'Google rejected the service account credentials: %1. '
                    . 'Check that the Merchant API is enabled on the Cloud project, that the service account '
                    . 'has been added as a user on the Merchant Center account, and that this server\'s clock '
                    . 'is accurate.',
                    $reason
                )
            );
        }

        return (string) $body['access_token'];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
