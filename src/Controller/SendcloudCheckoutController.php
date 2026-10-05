<?php
declare(strict_types=1);
namespace App\Controller;

use App\Classe\Cart;
use App\Entity\{Address, User};
use App\Repository\{AddressRepository, OrderRepository};
use App\Service\{OrderManager, SendcloudDelivery, ShippingConfiguration};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SendcloudCheckoutController extends AbstractController
{
    #[Route('/commande/sendcloud', name: 'app_sendcloud_checkout', methods: ['GET', 'POST'])]
    public function checkout(Request $request, Cart $cart, AddressRepository $addresses, OrderRepository $orders,
        SendcloudDelivery $delivery, ShippingConfiguration $configuration, OrderManager $manager, \Psr\Log\LoggerInterface $logger): Response
    {
        if (!$configuration->enabled) { return $this->redirectToRoute('app_order'); }
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        $lines = $cart->getCart(); $session = $request->getSession();
        $session->remove('shipping_legacy_fallback');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('sendcloud_checkout', $request->request->get('_token'))) { throw $this->createAccessDeniedException('Jeton de sécurité invalide.'); }
            // Le même formulaire ne peut pas créer une seconde commande après vidage du panier.
            if ($id = $session->get('current_order_id')) {
                if ($orders->findOneBy(['id' => $id, 'user' => $user, 'state' => 0])) { return $this->redirectToRoute('app_account_order', ['id_order' => $id]); }
            }
        } else { $session->remove('current_order_id'); if ($request->query->getBoolean('reset')) { $session->remove('sendcloud_checkout'); } }
        if (!$lines) { return $this->redirectToRoute('app_cart'); }
        if ($user->getAddresses()->isEmpty()) { return $this->redirectToRoute('app_account_address_form'); }
        $address = null; $offers = []; $selected = null; $point = null; $error = null;
        if ($request->isMethod('GET')) {
            $saved = $session->get('sendcloud_checkout', []);
            $resume = isset($saved['address_id']) ? $addresses->findOneBy(['id' => $saved['address_id'], 'user' => $user]) : null;
            if ($resume && ($saved['expires'] ?? 0) >= time() && ($saved['fingerprint'] ?? '') === $this->fingerprint($resume, $lines)) {
                $address = $resume; $offers = $saved['offers'];
                $selected = $offers[$saved['selected_key'] ?? ''] ?? null; $point = $saved['point'] ?? null;
            } else { $session->remove('sendcloud_checkout'); }
        }
        if ($request->isMethod('POST')) {
            $address = $addresses->findOneBy(['id' => $request->request->getInt('address'), 'user' => $user]);
            if (!$address) { throw $this->createNotFoundException('Adresse introuvable.'); }
            $fingerprint = $this->fingerprint($address, $lines);
            try {
                $saved = $session->get('sendcloud_checkout', []);
                $action = $request->request->getString('action');
                if (!in_array($action, ['address', 'method', 'point', 'confirm'], true)) { throw new \DomainException('Choisissez une étape de livraison valide.'); }
                if ($action === 'address' || ($saved['fingerprint'] ?? '') !== $fingerprint || ($saved['expires'] ?? 0) < time()) {
                    $offers = $delivery->offers($address, $lines);
                    $session->set('sendcloud_checkout', ['fingerprint' => $fingerprint, 'expires' => time() + 900, 'offers' => $offers, 'address_id' => $address->getId()]);
                    if ($action === 'point') { return $this->json(['error' => 'Votre sélection a expiré. Choisissez à nouveau votre livraison.'], 422); }
                } else {
                    $offers = $saved['offers']; $key = $request->request->getString('method'); $selected = $offers[$key] ?? null;
                    if (!$selected) { throw new \DomainException('Choisissez une méthode de livraison disponible.'); }
                    if ($selected['point_required'] && $action === 'method') {
                        $saved['selected_key'] = $key; unset($saved['point']);
                        $session->set('sendcloud_checkout', $saved);
                    } else {
                        $pointId = $selected['point_required'] ? $request->request->getInt('point') : null;
                        $selection = $delivery->select($address, $lines, $key, $pointId, $request->request->getString('post_number'));
                        if ($selection->priceCents !== $selected['price_cents']) {
                            throw new \DomainException('Le tarif de livraison a changé. Vérifiez à nouveau votre choix avant de continuer.');
                        }
                        if ($action === 'point') {
                            if (!$selected['point_required']) { throw new \DomainException('Cette livraison ne nécessite pas de point relais.'); }
                            $point = $selection->snapshot['service_point'];
                            $saved['selected_key'] = $key; $saved['point'] = $point;
                            $session->set('sendcloud_checkout', $saved);
                            return $this->json(['point' => $point]);
                        }
                        $order = $manager->create($user, $address, $selection->carrier(), $lines, $selection);
                        $session->set('current_order_id', $order->getId()); $session->remove('sendcloud_checkout'); $cart->remove();
                        return $this->redirectToRoute('app_account_order', ['id_order' => $order->getId()]);
                    }
                }
                if (!$offers) { $error = 'Aucune méthode compatible : aucune méthode de livraison n’est disponible pour cette adresse et votre sélection.'; }
            } catch (\DomainException $e) {
                $logger->warning('Checkout livraison refusé.', ['exception_type' => $e::class, 'message' => $e->getMessage()]);
                if (($action ?? '') === 'point') { return $this->json(['error' => $e->getMessage()], 422); }
                $error = $e->getMessage(); $offers = []; $selected = null; $session->remove('sendcloud_checkout');
            } catch (\Throwable $e) {
                $logger->error('Erreur technique checkout livraison.', ['exception_type' => $e::class,
                    'message' => 'Échec technique du checkout (détails privés masqués).']);
                $error = 'Une erreur technique empêche de vérifier la livraison. Réessayez ou utilisez la livraison classique.';
                if (($action ?? '') === 'point') { return $this->json(['error' => $error], 503); }
                $offers = []; $selected = null; $session->remove('sendcloud_checkout');
            }
        }
        return $this->render('order/sendcloud.html.twig', ['addresses' => $user->getAddresses(), 'address' => $address, 'offers' => $offers,
            'point' => $point, 'pickerPublicKey' => $delivery->pickerPublicKey(), 'selected' => $selected, 'error' => $error, 'cart' => $lines, 'totalWt' => $cart->getTotalWt(),
            'legacyFallbackEnabled' => $configuration->legacyFallbackEnabled]);
    }

    private function fingerprint(Address $address, array $lines): string
    {
        $items = [];
        foreach ($lines as $line) { $items[] = [$line['object']->getId(), $line['qty'], $line['object']->getPriceWt(), $line['object']->getShippingWeightGrams()]; }
        return hash('sha256', json_encode([$address->getId(), $address->getAddress(), $address->getPostal(), $address->getCity(), $address->getCountry(), $items], JSON_THROW_ON_ERROR));
    }
}
