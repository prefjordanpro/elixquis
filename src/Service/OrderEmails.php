<?php
namespace App\Service;

use App\Entity\{Order, OrderNotification};
use App\Classe\Mail;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mime\{Email, Address};
use Twig\Environment;

final class OrderEmails
{
    public const TITLES = [1 => 'Votre commande est confirmée', 2 => 'Votre commande est en préparation',
        3 => 'Votre commande est expédiée', 5 => 'Votre commande est livrée',
        4 => 'Votre commande est annulée', 6 => 'Votre commande est remboursée'];

    public function __construct(private EntityManagerInterface $em, private Mail $mail, private Environment $twig,
        private UrlGeneratorInterface $urls, private LoggerInterface $logger,
        #[Autowire('%env(default:auth.base_url:AUTH_BASE_URL)%')] private string $baseUrl,
        #[Autowire('%env(default:auth.sender:AUTH_SENDER)%')] private string $sender) {}

    /** Caller holds the order lock; no external calls inside the business transaction. */
    public function queue(Order $order): void
    {
        $state = $order->getState();
        if (!isset(self::TITLES[$state]) || !$order->getUser()) { return; }
        if ($this->em->getRepository(OrderNotification::class)->findOneBy(['order' => $order, 'state' => $state])) { return; }
        // Also check inserts not yet flushed in a nested transaction.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entry) {
            if ($entry instanceof OrderNotification && $entry->getOrder() === $order && $entry->getState() === $state) { return; }
        }
        $tracking = [];
        foreach ($order->getColis() as $colis) {
            if ($colis->getNumeroSuivi()) { $tracking[] = $colis->getNumeroSuivi(); }
        }
        if (!$tracking && $order->getShipment()?->getTrackingNumber()) { $tracking[] = $order->getShipment()->getTrackingNumber(); }
        $url = $order->getShipment()?->getTrackingUrl();
        $payload = ['recipient' => $order->getUser()->getEmail(), 'firstname' => $order->getUser()->getFirstname(),
            'reference' => $order->getReference(), 'state' => $state, 'title' => self::TITLES[$state],
            'carrier' => $order->getCarrierName(), 'delivery' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $order->getDelivery() ?? ''))),
            'total' => $order->getTotalCents() / 100, 'tracking' => $tracking,
            'trackingUrl' => $url && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null,
            'lines' => array_map(fn ($line) => ['name' => $line->getProductName(), 'qty' => $line->getProductQuantity(),
                'total' => $line->getProductPrice() * $line->getProductQuantity()], $order->getOrderDetails()->toArray())];
        $this->em->persist(new OrderNotification($order, $state, $payload));
    }

    public function dispatch(int $limit = 100): int
    {
        $db = $this->em->getConnection();
        if ($db->isTransactionActive()) { return 0; }
        $rows = $db->fetchAllAssociative('SELECT id, order_id, payload FROM order_notification WHERE status = ? ORDER BY id LIMIT '.max(1, min(1000, $limit)), ['pending']);
        $count = 0;
        foreach ($rows as $row) {
            // Autocommit claim BEFORE the network request. Concurrent workers and crash replays cannot resend.
            if ($db->executeStatement('UPDATE order_notification SET status = ? WHERE id = ? AND status = ?', ['attempting', $row['id'], 'pending']) !== 1) { continue; }
            try {
                $data = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                $data['link'] = rtrim($this->baseUrl, '/').$this->urls->generate('app_account');
                $email = (new Email())->from(new Address($this->sender, 'Elixquis'))->to($data['recipient'])
                    ->subject($data['title'].' — '.$data['reference'])
                    ->html($this->twig->render('emails/order.html.twig', $data))
                    ->text($this->twig->render('emails/order.txt.twig', $data));
                $this->mail->sendEmail($email);
                $status = 'sent'; ++$count;
            } catch (\Throwable $e) {
                $status = 'failed';
                $this->logger->warning('Courriel transactionnel commande indisponible.', ['order' => $row['order_id'], 'notification' => $row['id']]);
            }
            $db->executeStatement('UPDATE order_notification SET status = ? WHERE id = ?', [$status, $row['id']]);
        }
        return $count;
    }
}
