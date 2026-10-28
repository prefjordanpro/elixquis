<?php
declare(strict_types=1);
namespace App\DataFixtures;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo:revendeurs:remove', description: 'Retirer uniquement les revendeurs fictifs des fixtures dev/test.')]
final class RemoveRevendeurDemoCommand extends Command
{
    public function __construct(private RevendeurDemoFixtures $fixtures, private EntityManagerInterface $em) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->fixtures->remove($this->em);
        (new SymfonyStyle($input, $output))->success(sprintf('%d revendeur(s) de démonstration retiré(s). Les autres données sont conservées.', $count));
        return Command::SUCCESS;
    }
}
