<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController extends AbstractController
{
    #[Route('/catalogue', name: 'app_catalogue', methods: ['GET'])]
    public function catalogue(ProductRepository $products): Response
    {
        return $this->render('category/index.html.twig', ['category' => ['name' => 'Tous nos produits'],
            'products' => $products->findBy(['isActive' => true], ['name' => 'ASC'])]);
    }
    #[Route('/produit/{slug}', name: 'app_product')]
    public function index($slug, ProductRepository $productRepository): Response
    {

        $product = $productRepository->findOneBy(['slug' => $slug, 'isActive' => true]);

        if (!$product) {
        return $this->redirectToRoute('app_home');
        }

        return $this->render('product/index.html.twig', [
            'product' => $product,
        ]);
    }
}
