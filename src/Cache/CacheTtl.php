<?php

namespace App\Cache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Shared-cache TTLs (s-maxage): long when purges keep content fresh, short when only expiry does.
final class CacheTtl
{
    private const SHELL = 86400;
    private const ARTICLE = 3600;
    private const LISTING = 86400;

    public function __construct(
        #[Autowire(env: 'INVALIDATION_STRATEGY')]
        private readonly string $strategy,
        #[Autowire(env: 'int:SHORT_TTL')]
        private readonly int $shortTtl,
    ) {
    }

    public function shell(): int
    {
        return $this->ttl(self::SHELL);
    }

    public function article(): int
    {
        return $this->ttl(self::ARTICLE);
    }

    public function listing(): int
    {
        return $this->ttl(self::LISTING);
    }

    private function ttl(int $long): int
    {
        return 'ttl' === $this->strategy ? $this->shortTtl : $long;
    }
}
