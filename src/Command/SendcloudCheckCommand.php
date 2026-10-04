<?php
declare(strict_types=1);
namespace App\Command;
use App\Service\SendcloudService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:sendcloud:check', description: 'Vérifie la connexion Sendcloud par des lectures uniquement, sans créer d’expédition.')]
final class SendcloudCheckCommand extends Command
{
    public function __construct(private SendcloudService $api) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $addresses = $this->api->senderAddresses(); $contracts = $this->api->contracts();
            $io->success('Connexion Sendcloud v3 vérifiée par des lectures uniquement.');
            $io->text(sprintf('Adresses expéditeur : %d. Contrats : %d. Aucun envoi ou étiquette créé.', count($addresses), count($contracts)));
            return Command::SUCCESS;
        } catch (\DomainException $e) { $io->error($e->getMessage()); return Command::FAILURE; }
    }
}
