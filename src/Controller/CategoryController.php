<?php

namespace App\Controller;

use App\Entity\Category;
use App\Repository\ArticleRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    #[Route('/category/{slug}', name: 'category')]
    #[Cache(public: true, maxage: 0, smaxage: 86400)]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Category $category, ArticleRepository $articles): Response
    {
        return $this->render('category.html.twig', [
            'category' => $category,
            'articles' => $articles->findLatestByCategory($category, 20),
        ]);
    }
}
