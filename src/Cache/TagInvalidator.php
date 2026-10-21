<?php

namespace App\Cache;

use App\Entity\Article;
use FOS\HttpCacheBundle\CacheManager;

// Strategy B: purge only what is tagged with the change (Varnish xkey).
final class TagInvalidator implements ArticleInvalidator
{
    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    // The article page and every listing fragment that shows it.
    public function articleUpdated(Article $article): void
    {
        $this->purge(CacheTags::articles($article));
    }

    // Automatic listings the new article now enters.
    public function articlePublished(Article $article): void
    {
        $this->purge([
            CacheTags::latestList(),
            CacheTags::categoryList($article->getCategory()->getId()),
            CacheTags::authorList($article->getAuthor()->getId()),
        ]);
    }

    /**
     * @param list<string> $tags
     */
    private function purge(array $tags): void
    {
        $this->cacheManager->invalidateTags($tags);
        $this->cacheManager->flush();
    }
}
