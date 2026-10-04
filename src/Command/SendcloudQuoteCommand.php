<?php
declare(strict_types=1);
namespace App\Command;

use App\Repository\{AddressRepository, ProductRepository};
use App\Service\{SendcloudDelivery, ShippingConfiguration};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:sendcloud:quote', description: 'Devis uniquement, sans commande, expédition ou étiquette.')]
final class SendcloudQuoteCommand extends Command
{
    public function __construct(private SendcloudDelivery $delivery, private ShippingConfiguration $configuration,
        private AddressRepository $addresses, private ProductRepository $products) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('address', null, InputOption::VALUE_REQUIRED, 'ID de l’adresse destinataire existante.')
            ->addOption('product', null, InputOption::VALUE_REQUIRED, 'ID du produit existant.')
            ->addOption('quantity', null, InputOption::VALUE_REQUIRED, 'Nombre de bouteilles.', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            // Vérification locale avant tout appel réseau ; ne pas afficher les coordonnées.
            $this->configuration->sender();
            foreach (['address', 'product', 'quantity'] as $option) {
                if (!ctype_digit((string) $input->getOption($option)) || (int) $input->getOption($option) < 1) {
                    throw new \DomainException('Renseigner --address=ID --product=ID --quantity=N avec des entiers positifs.');
                }
            }
            $address = $this->addresses->find((int) $input->getOption('address'));
            $product = $this->products->find((int) $input->getOption('product'));
            if (!$address || !$product) { throw new \DomainException('Adresse ou produit introuvable.'); }
            $lines = [['object' => $product, 'qty' => (int) $input->getOption('quantity')]];
            $this->configuration->parcel($lines);
            $this->configuration->sellingPrice('0.00');
            $offers = $this->delivery->offers($address, $lines);
            $rows = [];
            foreach ($offers as $offer) {
                $rows[] = [$offer['carrier_name'], $offer['method_name'], $offer['kind'],
                    number_format($offer['price_cents'] / 100, 2, '.', '').' EUR'];
            }
            $io->table(['Transporteur', 'Méthode', 'Type', 'Tarif TTC sans marge'], $rows);
            $io->text('Devis uniquement (POST shipping-options, sans création). Aucune commande, expédition ou étiquette créée.');
            if (!$offers) { $io->warning('Aucune méthode compatible et tarifée retournée.'); }
            return Command::SUCCESS;
        } catch (\DomainException $e) { $io->error($e->getMessage()); return Command::FAILURE; }
    }
}
