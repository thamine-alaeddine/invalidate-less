<?php

namespace App\Cache;

use App\Entity\Article;

// Invalidation strategy run after an article changes.
interface ArticleInvalidator
{
    public function invalidateArticle(Article $article): void;
}
