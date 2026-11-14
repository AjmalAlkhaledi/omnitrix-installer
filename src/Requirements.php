<?php

declare(strict_types=1);

namespace Omnitrix\Installer;

use function extension_loaded;
use function phpversion;
use function version_compare;

use const PHP_VERSION;

/**
 * Checks the platform an Omnitrix application needs. Installing PHP and its extensions is the
 * developer's responsibility; the installer only reports what is missing.
 */
final class Requirements
{
    public const string PHP = '8.3.0';

    public const string OPENSWOOLE = '26.2.0';

    public const string REDIS = '6.3.0';

    /**
     * @param (callable(string): (string|false))|null $extensionVersion defaults to phpversion()
     * @param (callable(string): bool)|null           $extensionLoaded  defaults to extension_loaded()
     */
    public function __construct(
        private readonly string $phpVersion = PHP_VERSION,
        private $extensionVersion = null,
        private $extensionLoaded = null,
    ) {}

    /**
     * @return list<array{name: string, required: string, found: string, ok: bool}>
     */
    public function check(): array
    {
        return [
            ['name' => 'PHP', 'required' => '>= ' . self::PHP, 'found' => $this->phpVersion, 'ok' => version_compare($this->phpVersion, self::PHP, '>=')],
            $this->extension('openswoole', 'ext-openswoole', self::OPENSWOOLE),
            $this->extension('redis', 'ext-redis (phpredis)', self::REDIS),
            $this->extension('pdo_mysql', 'ext-pdo_mysql', null),
        ];
    }

    public function satisfied(): bool
    {
        foreach ($this->check() as $requirement) {
            if (!$requirement['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return array{name: string, required: string, found: string, ok: bool} */
    private function extension(string $extension, string $label, ?string $minimum): array
    {
        $loaded = ($this->extensionLoaded ?? extension_loaded(...))($extension);
        $version = $loaded ? ($this->extensionVersion ?? phpversion(...))($extension) : false;
        $found = $loaded ? ($version === false ? 'loaded' : $version) : 'missing';
        $ok = $loaded && ($minimum === null || ($version !== false && version_compare($version, $minimum, '>=')));

        return ['name' => $label, 'required' => $minimum === null ? 'loaded' : '>= ' . $minimum, 'found' => $found, 'ok' => $ok];
    }
}
