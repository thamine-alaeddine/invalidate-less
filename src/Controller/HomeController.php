<?php

namespace App\Controller;

use App\Cache\CacheTtl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    #[Cache(public: true, maxage: 60)]
    public function __invoke(CacheTtl $ttl): Response
    {
        return $this->render('home.html.twig')->setSharedMaxAge($ttl->shell());
    }
}
