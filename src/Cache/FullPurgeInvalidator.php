<?php

namespace App\Cache;

use App\Entity\Article;
use FOS\HttpCacheBundle\CacheManager;

// Strategy A: ban every cached page, whatever changed.
final class FullPurgeInvalidator implements ArticleInvalidator
{
    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    public function invalidateArticle(Article $article): void
    {
        $this->cacheManager->invalidateRegex('.*');
        $this->cacheManager->flush();
    }
}
