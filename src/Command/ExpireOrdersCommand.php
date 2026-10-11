<?php

namespace App\Command;

use App\Repository\OrderRepository;
use App\Service\StripePayment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:commandes:annuler-paiements-expires', aliases: ['app:orders:expire'], description: 'Annule les commandes impayées de plus de 24 heures après vérification Stripe.')]
class ExpireOrdersCommand extends Command
{
    public function __construct(private OrderRepository $orders, private StripePayment $payment) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Vérifier Stripe et afficher les actions prévues, sans écriture ni expiration de session.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->orders->createQueryBuilder('o')->where('o.state = 0')->andWhere('o.createdAt < :limit')
            ->setParameter('limit', new \DateTime('-24 hours'))->getQuery()->getResult();
        foreach ($expired as $order) {
            try { $result = $this->payment->expireUnpaid($order, dryRun: $input->getOption('dry-run')); }
            catch (\DomainException|\Stripe\Exception\ApiErrorException $e) {
                $output->writeln('<error>Vérification impossible pour '.$order->getReference().'. Aucune annulation ; relancer le traitement.</error>');
                // Une transaction Doctrine en échec peut fermer l'EntityManager : arrêter proprement.
                return Command::FAILURE;
            }
            $message = match ($result) {
                'would_cancel_uninitiated' => '→ paiement jamais initié depuis >24 h → serait annulée',
                'cancelled_uninitiated' => '→ paiement jamais initié depuis >24 h → annulée',
                'uncertain' => '→ tentative Stripe initiée mais état incertain → aucune annulation automatique ; vérification manuelle nécessaire',
                'would_cancel_expired' => '→ session expirée → serait annulée',
                'unverifiable' => '→ Aucune session Stripe identifiable → aucune annulation automatique ; vérification manuelle nécessaire',
                'would_expire' => '→ session ouverte impayée → serait expirée puis commande annulée si Stripe confirme l’expiration',
                'would_sync' => '→ déjà payée → serait synchronisée Payée',
                'cancelled' => 'annulée : paiement non finalisé dans le délai imparti',
                'paid' => 'synchronisée comme payée',
                'pending' => '→ paiement en traitement ou à vérifier → aucune action',
                default => 'inchangée',
            };
            $output->writeln('Commande #'.$order->getId().' '.$message.'.');
        }
        return Command::SUCCESS;
    }
}
