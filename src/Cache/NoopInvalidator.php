<?php

namespace App\Cache;

use App\Entity\Article;

// TTL-only strategy: purge nothing, content refreshes when the short TTL expires.
final class NoopInvalidator implements ArticleInvalidator
{
    public function articleUpdated(Article $article): void
    {
    }

    public function articlePublished(Article $article): void
    {
    }
}
