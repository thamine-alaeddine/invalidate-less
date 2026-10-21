<?php

namespace App\Controller;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    #[Route('/article/{slug}', name: 'article')]
    #[Cache(public: true, maxage: 60, smaxage: 3600)]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Article $article, ArticleRepository $articles): Response
    {
        return $this->render('article.html.twig', [
            'article' => $article,
            'related' => $articles->findLatestByCategory($article->getCategory(), 6),
        ]);
    }
}
