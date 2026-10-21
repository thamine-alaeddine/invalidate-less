<?php

namespace App\Twig;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Twig\Attribute\AsTwigFunction;

final class NavigationExtension
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    /**
     * @return list<Category>
     */
    #[AsTwigFunction('nav_categories')]
    public function navCategories(): array
    {
        return $this->categories->findBy([], ['name' => 'ASC']);
    }
}
