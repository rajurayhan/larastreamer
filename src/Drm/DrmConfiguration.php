<?php

declare(strict_types=1);

namespace Raju\Streamer\Drm;

use Raju\Streamer\Exceptions\DrmConfigurationException;

final class DrmConfiguration
{
    private const ADVANCED_KEYS = ['audioRobustness', 'videoRobustness'];

    private const HEADER_NAME_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/";

    private const KEY_SYSTEM_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]+$/';

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /** @var array<string, string> */
    private readonly array $licenseServers;

    /** @var array<string, string> */
    private readonly array $licenseHeaders;

    /** @var array<string, string> */
    private readonly array $contentHeaders;

    /** @var array<string, string> */
    private readonly array $certificateHeaders;

    /** @var array<string, array<string, list<string>>> */
    private readonly array $advanced;

    /**
     * @param  array<mixed, mixed>  $licenseServers
     * @param  array<mixed, mixed>  $licenseHeaders
     * @param  array<mixed, mixed>  $contentHeaders
     * @param  array<mixed, mixed>  $certificateHeaders
     * @param  array<mixed, mixed>  $advanced
     */
    public function __construct(
        array $licenseServers,
        array $licenseHeaders = [],
        private readonly ?string $manifestUrl = null,
        array $contentHeaders = [],
        private readonly ?string $fairPlayCertificateUrl = null,
        array $certificateHeaders = [],
        array $advanced = [],
    ) {
        if ($licenseServers === []) {
            throw new DrmConfigurationException('At least one DRM license server is required.');
        }

        $this->licenseServers = $this->validateServers($licenseServers);
        $this->licenseHeaders = $this->validateHeaders($licenseHeaders);
        $this->contentHeaders = $this->validateHeaders($contentHeaders);
        $this->certificateHeaders = $this->validateHeaders($certificateHeaders);
        $this->advanced = $this->validateAdvanced($advanced);

        if ($manifestUrl !== null) {
            $this->validateUrl($manifestUrl);
        }

        if ($fairPlayCertificateUrl !== null) {
            $this->validateUrl($fairPlayCertificateUrl);
        }
    }

    public function manifestUrl(): ?string
    {
        return $this->manifestUrl;
    }

    /**
     * @return array{
     *     servers: array<string, string>,
     *     license_headers: array<string, string>,
     *     content_headers: array<string, string>,
     *     certificate_url: string|null,
     *     certificate_headers: array<string, string>,
     *     advanced: array<string, array<string, list<string>>>
     * }
     */
    public function toArray(): array
    {
        return [
            'servers' => $this->licenseServers,
            'license_headers' => $this->licenseHeaders,
            'content_headers' => $this->contentHeaders,
            'certificate_url' => $this->fairPlayCertificateUrl,
            'certificate_headers' => $this->certificateHeaders,
            'advanced' => $this->advanced,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $servers
     * @return array<string, string>
     */
    private function validateServers(array $servers): array
    {
        $validated = [];

        foreach ($servers as $keySystem => $url) {
            if (! is_string($keySystem) || ! is_string($url)) {
                throw new DrmConfigurationException('Invalid DRM license server configuration.');
            }

            $this->validateKeySystem($keySystem);
            $this->validateUrl($url);
            $validated[$keySystem] = $url;
        }

        return $validated;
    }

    /**
     * @param  array<mixed, mixed>  $headers
     * @return array<string, string>
     */
    private function validateHeaders(array $headers): array
    {
        $validated = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name)
                || ! is_string($value)
                || preg_match(self::HEADER_NAME_PATTERN, $name) !== 1
                || str_contains($value, "\r")
                || str_contains($value, "\n")) {
                throw new DrmConfigurationException('Invalid DRM request header.');
            }

            $validated[$name] = $value;
        }

        return $validated;
    }

    /**
     * @param  array<mixed, mixed>  $advanced
     * @return array<string, array<string, list<string>>>
     */
    private function validateAdvanced(array $advanced): array
    {
        $validated = [];

        foreach ($advanced as $keySystem => $settings) {
            if (! is_string($keySystem) || ! is_array($settings)) {
                throw new DrmConfigurationException('Unsupported advanced DRM configuration.');
            }

            $this->validateKeySystem($keySystem);
            $validatedSettings = [];

            foreach ($settings as $name => $values) {
                if (! is_string($name)
                    || ! is_array($values)
                    || ! in_array($name, self::ADVANCED_KEYS, true)
                    || ! array_is_list($values)
                    || $values === []) {
                    throw new DrmConfigurationException('Unsupported advanced DRM configuration.');
                }

                $validatedValues = [];

                foreach ($values as $value) {
                    if (! is_string($value) || $value === '') {
                        throw new DrmConfigurationException('Unsupported advanced DRM configuration.');
                    }

                    $validatedValues[] = $value;
                }

                $validatedSettings[$name] = $validatedValues;
            }

            $validated[$keySystem] = $validatedSettings;
        }

        return $validated;
    }

    private function validateKeySystem(string $keySystem): void
    {
        if (preg_match(self::KEY_SYSTEM_PATTERN, $keySystem) !== 1) {
            throw new DrmConfigurationException('Invalid DRM key-system identifier.');
        }
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! isset($parts['scheme'], $parts['host'])) {
            throw new DrmConfigurationException('Invalid DRM endpoint URL.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = trim(strtolower($parts['host']), '[]');

        if ($scheme !== 'https' && ($scheme !== 'http' || ! in_array($host, self::LOOPBACK_HOSTS, true))) {
            throw new DrmConfigurationException('DRM endpoint URLs require HTTPS.');
        }
    }
}
