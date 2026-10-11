<?php
declare(strict_types=1);

namespace App\Command;

use App\Repository\ProductRepository;
use App\Service\Colisage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\{Autowire, When};

#[When(env: 'dev')]
#[AsCommand(name: 'app:colisage:debug', description: 'Diagnostic local du colisage du checkout (développement uniquement).')]
final class ColisageDebugCommand extends Command
{
    public function __construct(
        private Colisage $colisage,
        private ProductRepository $products,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('product', null, InputOption::VALUE_REQUIRED, 'ID du produit existant.')
            ->addOption('quantity', null, InputOption::VALUE_REQUIRED, 'Nombre de bouteilles.', '1')
            ->addOption('line', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Panier : ID:QUANTITE, option répétable (à la place de --product).')
            ->setHelp('Exemples : --product=1 --quantity=7 ou --line=1:3 --line=2:4. Lecture des produits et emballages configurés, sans appel transporteur ni écriture en base.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment !== 'dev') {
            $io->error('Ce diagnostic est réservé à l’environnement dev.');
            return Command::FAILURE;
        }

        try {
            $entries = $input->getOption('line');
            if ($entries && ($input->getOption('product') !== null || $input->getOption('quantity') !== '1')) {
                throw new \DomainException('Utilisez --product et --quantity, ou uniquement des options --line=ID:QUANTITE.');
            }
            if (!$entries) {
                $entries = [$input->getOption('product').':'.$input->getOption('quantity')];
            }
            $quantities = [];
            foreach ($entries as $entry) {
                if (!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D', $entry, $matches)
                    || filter_var($matches[1], FILTER_VALIDATE_INT) === false
                    || filter_var($matches[2], FILTER_VALIDATE_INT) === false) {
                    throw new \DomainException('Renseignez --product=ID --quantity=N ou --line=ID:N avec des entiers positifs.');
                }
                $id = (int) $matches[1];
                $quantity = (int) $matches[2];
                if ($quantity > PHP_INT_MAX - ($quantities[$id] ?? 0)) {
                    throw new \DomainException('Quantité trop élevée.');
                }
                $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
            }
            $lines = [];
            foreach ($quantities as $id => $quantity) {
                $product = $this->products->find($id);
                if (!$product) { throw new \DomainException(sprintf('Produit #%d introuvable.', $id)); }
                $lines[] = ['object' => $product, 'qty' => $quantity];
            }

            // Même point d’entrée que SendcloudDelivery::offers(), sans service transporteur.
            $plan = $this->colisage->plan($lines);
            $io->title('Diagnostic colisage — développement');
            foreach ($plan as $index => $parcel) {
                $box = $parcel['emballage'];
                $dimensions = $box['dimensions'];
                $io->section(sprintf('Colis %d — %s (ID : %s)', $index + 1, $box['nom'], $box['id'] ?? 'historique'));
                $io->definitionList(
                    ['Capacité' => $box['capacite'].' bouteilles'],
                    ['Unités affectées' => $parcel['nombre_unites']],
                    ['Poids produits' => $parcel['poids_produits_g'].' g'],
                    ['Poids emballage' => $box['poids_emballage_g'].' g'],
                    ['Poids total du colis' => $parcel['poids_total_g'].' g'],
                    ['Dimensions L × l × H' => implode(' × ', [$dimensions['length'], $dimensions['width'], $dimensions['height']]).' '.$dimensions['unit']],
                );
                $io->table(['Produit ID', 'Produit', 'Quantité', 'Poids unitaire (g)', 'Poids produits (g)'],
                    array_map(fn (array $item) => [$item['produit_id'], $item['nom'], $item['quantite'], $item['poids_unitaire_g'], $item['poids_total_g']], $parcel['contenu']));
            }
            $io->success(sprintf('Expédition : %d colis / %d g', count($plan), array_sum(array_column($plan, 'poids_total_g'))));
            return Command::SUCCESS;
        } catch (\DomainException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
