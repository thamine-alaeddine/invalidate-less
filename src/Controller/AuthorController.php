<?php

namespace App\Controller;

use App\Entity\Author;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class AuthorController extends AbstractController
{
    #[Route('/author/{slug}', name: 'author')]
    #[Cache(public: true, maxage: 60, smaxage: 86400)]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Author $author): Response
    {
        return $this->render('author.html.twig', ['author' => $author]);
    }
}
