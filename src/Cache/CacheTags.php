<?php

namespace App\Cache;

use App\Entity\Article;

// Cache tag names shared by the responses that tag themselves and the strategies that purge them.
final class CacheTags
{
    /**
     * @return list<string>
     */
    public static function articles(Article ...$articles): array
    {
        return array_map(static fn (Article $article) => 'article-'.$article->getId(), $articles);
    }

    public static function latestList(): string
    {
        return 'list-latest';
    }

    public static function categoryList(int $categoryId): string
    {
        return 'list-category-'.$categoryId;
    }

    public static function authorList(int $authorId): string
    {
        return 'list-author-'.$authorId;
    }
}
