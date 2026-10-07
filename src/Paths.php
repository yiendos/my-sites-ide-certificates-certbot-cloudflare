<?php

namespace Yiendos\MySitesIde\Certificates\CertbotCloudflare;

use RuntimeException;

/**
 * Where this plugin's files live. Nothing the user creates is kept in the
 * package: the Cloudflare credentials go in the IDE's storage/plugins/certbot-cloudflare/,
 * and the certificates in the IDE's shared storage/certificates/ - the
 * Let's Encrypt live/ + archive/ tree nginx mounts, whichever plugin issued them.
 *
 * The IDE root comes from IDE_ROOT, which the my-sites-ide bootstrap sets
 * before any plugin command runs (and which docker-compose.yml interpolates).
 */
final class Paths
{
    public const STORAGE = 'storage/plugins/certbot-cloudflare';
    public const CERTIFICATES = 'storage/certificates';

    /**
     * Where the IDE mounts this plugin's storage/plugins/certbot-cloudflare/ in
     * the container ("storage": true in composer.json)
     */
    public const CONTAINER_CREDENTIALS = '/storage';

    /**
     * The my-sites-ide project root
     *
     * @return string
     */
    public static function root(): string
    {
        $root = getenv('IDE_ROOT');

        if ($root === false || $root === '') {
            throw new RuntimeException('IDE_ROOT is not set - run this command through the my-sites-ide CLI.');
        }

        return rtrim($root, '/');
    }

    /**
     * A credentials file on the host, e.g. credentials.ini
     *
     * @param string $file
     * @return string
     */
    public static function credentials(string $file = ''): string
    {
        return self::storage(self::STORAGE, $file);
    }

    /**
     * The shared certificate store on the host, e.g. live/<domain>
     *
     * @param string $file
     * @return string
     */
    public static function certificates(string $file = ''): string
    {
        return self::storage(self::CERTIFICATES, $file);
    }

    /**
     * A file shipped with this package, e.g. stubs/credentials.ini
     *
     * @param string $file
     * @return string
     */
    public static function package(string $file = ''): string
    {
        return dirname(__DIR__) . ($file === '' ? '' : "/{$file}");
    }

    /**
     * An absolute path shown relative to the IDE root, for user-facing messages
     *
     * @param string $path
     * @return string
     */
    public static function relative(string $path): string
    {
        $root = self::root() . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Creates a storage directory on first use - Docker would otherwise
     * create the bind mount itself, owned by root on Linux hosts
     *
     * @param string $directory
     * @param string $file
     * @return string
     */
    private static function storage(string $directory, string $file): string
    {
        $path = self::root() . "/{$directory}";

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $file === '' ? $path : "{$path}/{$file}";
    }
}
