<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    #[Route('/categorie/{slug}', name: 'app_category')]
    public function index($slug, CategoryRepository $categoryRepository, ProductRepository $products): Response
    {
        $category = $categoryRepository->findOneBySlug($slug);

        if (!$category) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('category/index.html.twig', [
            'category' => $category,
            'products' => $products->findBy(['category' => $category, 'isActive' => true], ['name' => 'ASC']),
        ]);
    }
}
