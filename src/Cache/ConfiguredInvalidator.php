<?php

namespace App\Cache;

use App\Entity\Article;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

// Runs the strategy named by INVALIDATION_STRATEGY, read at runtime so benchmarks can switch without a rebuild.
final class ConfiguredInvalidator implements ArticleInvalidator
{
    public function __construct(
        #[AutowireLocator(['full' => FullPurgeInvalidator::class, 'tags' => TagInvalidator::class])]
        private readonly ContainerInterface $strategies,
        #[Autowire(env: 'INVALIDATION_STRATEGY')]
        private readonly string $strategy,
    ) {
        // Fails before the editor touches the database.
        if (!$strategies->has($strategy)) {
            throw new \InvalidArgumentException(\sprintf('Unknown INVALIDATION_STRATEGY "%s", expected "full" or "tags".', $strategy));
        }
    }

    public function invalidateArticle(Article $article): void
    {
        $this->strategies->get($this->strategy)->invalidateArticle($article);
    }
}
