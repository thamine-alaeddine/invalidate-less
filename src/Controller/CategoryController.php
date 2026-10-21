<?php

namespace App\Controller;

use App\Cache\ArticleTags;
use App\Entity\Category;
use App\Repository\ArticleRepository;
use FOS\HttpCache\ResponseTagger;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    #[Route('/category/{slug}', name: 'category')]
    #[Cache(public: true, maxage: 60, smaxage: 86400)]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Category $category, ArticleRepository $articles, ResponseTagger $tagger): Response
    {
        $latest = $articles->findLatestByCategory($category, 20);
        $tagger->addTags(ArticleTags::of(...$latest));

        return $this->render('category.html.twig', [
            'category' => $category,
            'articles' => $latest,
        ]);
    }
}
