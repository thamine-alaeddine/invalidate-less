<?php

namespace App\Cache;

use App\Entity\Article;

// Invalidation strategy run after the editor changes content.
interface ArticleInvalidator
{
    public function articleUpdated(Article $article): void;

    public function articlePublished(Article $article): void;
}
