<?php

namespace App\Cache;

use App\Entity\Article;
use FOS\HttpCacheBundle\CacheManager;

// Strategy A: ban every cached page and fragment, whatever changed.
final class FullPurgeInvalidator implements ArticleInvalidator
{
    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    public function articleUpdated(Article $article): void
    {
        $this->banAll();
    }

    public function articlePublished(Article $article): void
    {
        $this->banAll();
    }

    private function banAll(): void
    {
        $this->cacheManager->invalidateRegex('.*');
        $this->cacheManager->flush();
    }
}
