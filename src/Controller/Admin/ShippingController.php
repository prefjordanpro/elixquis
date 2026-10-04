<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\Shipment;
use App\Repository\OrderRepository;
use App\Service\{SendcloudService, SendcloudShipping};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ShippingController extends AbstractController
{
    #[Route('/admin/commande/{id}/expedition/creer', name: 'app_admin_shipping_create', methods: ['POST'])]
    public function create(int $id, Request $request, OrderRepository $orders, SendcloudShipping $shipping): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('shipping_create_'.$id, $request->request->get('_token'))) { throw $this->createAccessDeniedException('Jeton de sécurité invalide.'); }
        $order = $orders->find($id); if (!$order) { throw $this->createNotFoundException(); }
        try {
            $shipment = $shipping->create($order);
            $this->addFlash($shipment->getState() === 'ready' ? 'success' : 'warning', $shipment->getState() === 'ready' ? 'Expédition Sendcloud disponible.' : 'Une demande existe déjà. Vérifiez son résultat avant toute autre opération.');
        } catch (\DomainException $e) { $this->addFlash('danger', $e->getMessage()); }
        return $this->redirectToRoute('admin_order_detail', ['entityId' => $id]);
    }
    #[Route('/admin/commande/{id}/expedition/verifier', name: 'app_admin_shipping_sync', methods: ['POST'])]
    public function sync(int $id, Request $request, EntityManagerInterface $em, SendcloudShipping $shipping): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('shipping_sync_'.$id, $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $shipment = $em->getRepository(Shipment::class)->findOneBy(['order' => $id]); if (!$shipment) { throw $this->createNotFoundException(); }
        try { $shipping->synchronize($shipment); $this->addFlash('success', 'Suivi de l’expédition actualisé.'); }
        catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('admin_order_detail', ['entityId' => $id]);
    }
    #[Route('/admin/commande/{id}/expedition/etiquette', name: 'app_admin_shipping_label', methods: ['GET'])]
    public function label(int $id, EntityManagerInterface $em, SendcloudService $api): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $shipment = $em->getRepository(Shipment::class)->findOneBy(['order' => $id]);
        if (!$shipment || !$shipment->hasLabel() || !$shipment->getParcelId()) { throw $this->createNotFoundException(); }
        try { return new Response($api->label($shipment->getParcelId()), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="etiquette-commande-'.$id.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']); }
        catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage()); return $this->redirectToRoute('admin_order_detail', ['entityId' => $id]); }
    }
}
