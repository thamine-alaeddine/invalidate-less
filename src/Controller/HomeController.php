<?php

namespace App\Controller;

use App\Cache\ArticleTags;
use App\Repository\ArticleRepository;
use FOS\HttpCache\ResponseTagger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    #[Cache(public: true, maxage: 60, smaxage: 86400)]
    public function __invoke(ArticleRepository $articles, ResponseTagger $tagger): Response
    {
        $latest = $articles->findLatest(20);
        $tagger->addTags(ArticleTags::of(...$latest));

        return $this->render('home.html.twig', [
            'articles' => $latest,
        ]);
    }
}
