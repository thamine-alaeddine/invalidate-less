<?php

namespace App\Cache;

use App\Entity\Article;

// Cache tag names shared by the pages that tag themselves and the strategies that purge them.
final class ArticleTags
{
    /**
     * @return list<string>
     */
    public static function of(Article ...$articles): array
    {
        return array_map(static fn (Article $article) => 'article-'.$article->getId(), $articles);
    }
}
