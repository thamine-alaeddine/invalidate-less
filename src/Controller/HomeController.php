<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    #[Cache(public: true, maxage: 0, smaxage: 86400)]
    public function __invoke(ArticleRepository $articles): Response
    {
        return $this->render('home.html.twig', [
            'articles' => $articles->findLatest(20),
        ]);
    }
}
