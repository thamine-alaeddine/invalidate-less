<?php

namespace App\Controller;

use App\Cache\CacheTtl;
use App\Entity\Category;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    #[Route('/category/{slug}', name: 'category')]
    #[Cache(public: true, maxage: 60)]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Category $category, CacheTtl $ttl): Response
    {
        return $this->render('category.html.twig', ['category' => $category])->setSharedMaxAge($ttl->shell());
    }
}
