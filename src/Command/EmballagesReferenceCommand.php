<?php
declare(strict_types=1);
namespace App\Command;
use App\Entity\Emballage;
use App\Repository\EmballageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:emballages:references', description: 'Créer les brouillons de cartons indicatifs, sans poids inventé ni activation.')]
final class EmballagesReferenceCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private EmballageRepository $repository, #[Autowire('%kernel.environment%')] private string $environment) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('development', null, InputOption::VALUE_NONE, 'Configurer les quatre cartons de test avec les poids provisoires (dev/test uniquement).');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = 0;
        $development = $input->getOption('development');
        $io = new SymfonyStyle($input, $output);
        if ($development && !in_array($this->environment, ['dev', 'test'], true)) {
            $io->error('Les emballages provisoires sont réservés aux environnements dev/test.');
            return Command::FAILURE;
        }
        $tares = [1 => 300, 2 => 500, 3 => 700, 6 => 1200];
        foreach ([[1, 12, 12, 38.5], [2, 22, 11.5, 39.5], [3, 31.5, 12.5, 39.5], [6, 32, 22, 41]] as [$capacity, $length, $width, $height]) {
            $name = 'Carton '.$capacity.' bouteille'.($capacity > 1 ? 's' : '');
            $box = $this->repository->findOneBy(['nom' => $name]);
            if ($box && !$development) { continue; }
            if (!$box) { $box = new Emballage(); $this->em->persist($box); ++$created; }
            $box->setNom($name)->setCapacite($capacity)->setLongueurCm($length)->setLargeurCm($width)->setHauteurCm($height);
            if ($development) { $box->setPoidsVideGrammes($tares[$capacity])->setPoidsMaxGrammes(null)->setActif(true); }
        }
        $this->em->flush();
        if ($development) {
            $io->warning('Quatre cartons actifs de développement configurés. Poids provisoires, non certifiés fabricant, modifiables dans Administration > Emballages. Relancer cette option réapplique ces valeurs de test. Aucun envoi ni étiquette créé.');
            return Command::SUCCESS;
        }
        (new SymfonyStyle($input, $output))->success($created.' brouillon(s) créé(s). Dimensions indicatives à mesurer ; renseignez le poids avec protections avant activation. Aucun devis ou envoi créé.');
        return Command::SUCCESS;
    }
}
