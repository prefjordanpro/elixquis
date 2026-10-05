<?php

namespace App\Controller\Account;

use App\Classe\Cart;
use App\Entity\Address;
use App\Form\AddressUserType;
use App\Repository\AddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


final class AddressController extends AbstractController
{

    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/compte/adresses', name: 'app_account_addresses')]
    public function index(): Response
    {
        return $this->render('account/address/index.html.twig');
    }

    #[Route('/compte/adresses/{id}/supprimer', name: 'app_account_address_delete', methods: ['POST'])]
    public function delete(int $id, AddressRepository $addressRepository, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('address_delete_'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $address = $addressRepository->findOneById($id);
        if(!$address OR $address->getUser() != $this->getUser()) {
            return $this->redirectToRoute('app_account_addresses');
        }
        $this->addFlash(
            'success',
            "Votre adresse est correctement supprimée"
        );

        $this->entityManager->remove($address);
        $this->entityManager->flush();
        
        return $this->redirectToRoute('app_account_addresses');
    }

    #[Route('/compte/adresse/ajouter/{id}', name: 'app_account_address_form', defaults: ['id' => null], methods: ['GET', 'POST'])]
    public function form(Request $request, $id, AddressRepository $addressRepository, Cart $cart): Response
    {
        if($id) {
            $address = $addressRepository->findOneById($id);
            if(!$address OR $address->getUser() != $this->getUser()) {
                return $this->redirectToRoute('app_account_addresses');
            }
        } else {
            $address = new Address();
            $address->setUser($this->getUser());
        }

        $form = $this->createForm(AddressUserType::class, $address);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() ){
            $this->entityManager->persist($address);
            $this->entityManager->flush();

            
            $this->addFlash(
            'success',
            "Votre adresse est correctement sauvegardée"
            );

            if ($cart->fullQuantity() > 0) {
                return $request->getSession()->get('shipping_legacy_fallback', false)
                    ? $this->redirectToRoute('app_order', ['fallback' => 1])
                    : $this->redirectToRoute('app_sendcloud_checkout');
            }

            return $this->redirectToRoute("app_account_addresses");
        }

        return $this->render('account/address/form.html.twig', [
            'addressForm' => $form
        ]);
    }

}


?>
