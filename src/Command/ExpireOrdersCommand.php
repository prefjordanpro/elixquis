<?php

namespace App\Command;

use App\Repository\OrderRepository;
use App\Service\StripePayment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:orders:expire', description: 'Libère les réservations impayées datant de plus d’une heure.')]
class ExpireOrdersCommand extends Command
{
    public function __construct(private OrderRepository $orders, private StripePayment $payment) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->orders->createQueryBuilder('o')->where('o.state = 0')->andWhere('o.createdAt < :limit')
            ->setParameter('limit', new \DateTime('-1 hour'))->getQuery()->getResult();
        foreach ($expired as $order) {
            // Les erreurs sont remontées au planificateur pour permettre une nouvelle tentative.
            if ($order->getStripeSessionId()) {
                try { $this->payment->verify($order); continue; }
                catch (\DomainException $e) { /* Paiement non confirmé : tenter l’expiration. */ }
            }
            $this->payment->cancel($order);
            $output->writeln('Commande '.$order->getReference().' annulée.');
        }
        return Command::SUCCESS;
    }
}
