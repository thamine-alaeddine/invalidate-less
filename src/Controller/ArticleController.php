<?php

namespace App\Controller;

use App\Cache\ArticleTags;
use App\Entity\Article;
use App\Repository\ArticleRepository;
use FOS\HttpCache\ResponseTagger;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    #[Route('/article/{slug}', name: 'article')]
    #[Cache(public: true, maxage: 60, smaxage: 3600)]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Article $article, ArticleRepository $articles, ResponseTagger $tagger): Response
    {
        $related = array_slice(array_values(array_filter(
            $articles->findLatestByCategory($article->getCategory(), 6),
            static fn (Article $other) => $other->getId() !== $article->getId(),
        )), 0, 5);
        $tagger->addTags(ArticleTags::of($article, ...$related));

        return $this->render('article.html.twig', [
            'article' => $article,
            'related' => $related,
        ]);
    }
}
