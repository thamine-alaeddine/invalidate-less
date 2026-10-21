<?php

namespace App\Cache;

use App\Entity\Article;
use FOS\HttpCacheBundle\CacheManager;

// Strategy B: purge only the pages tagged with the changed article (Varnish xkey).
final class TagInvalidator implements ArticleInvalidator
{
    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    public function invalidateArticle(Article $article): void
    {
        $this->cacheManager->invalidateTags(CacheTags::articles($article));
        $this->cacheManager->flush();
    }
}
