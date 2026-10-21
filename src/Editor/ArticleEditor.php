<?php

namespace App\Editor;

use App\Cache\ArticleInvalidator;
use App\Entity\Article;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final class ArticleEditor
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly EntityManagerInterface $em,
        private readonly ArticleInvalidator $invalidator,
        private readonly ClockInterface $clock,
    ) {
    }

    // Invalidation runs synchronously so callers only return once caches are purged.
    public function update(int $id): ?Article
    {
        $article = $this->articles->find($id);
        if (null === $article) {
            return null;
        }

        $article->edit($this->clock->now());
        $this->em->flush();
        $this->invalidator->invalidateArticle($article);

        return $article;
    }
}
