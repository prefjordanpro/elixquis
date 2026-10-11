<?php
namespace App\Command;

use App\Service\OrderEmails;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:orders:send-emails', description: 'Traite les emails transactionnels en attente, sans rejouer les tentatives.')]
final class SendOrderEmailsCommand extends Command
{
    public function __construct(private OrderEmails $emails) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->emails->dispatch().' email(s) accepté(s).');
        return Command::SUCCESS;
    }
}
