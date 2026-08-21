<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Mysql\FeedConfig;
use App\Service\FeedImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:feed:update',
    description: 'Aktualisiert vollautomatisch alle eingerichteten Shopping-Feeds.',
)]
class FeedUpdateCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private FeedImporter $importer
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Starte automatische Feed-Synchronisierung');

        // Alle Feeds aus der Datenbank laden
        $feeds = $this->em->getRepository(FeedConfig::class)->findAll();

        if (empty($feeds)) {
            $io->warning('Keine Feeds in der Datenbank konfiguriert.');
            return Command::SUCCESS;
        }

        foreach ($feeds as $feed) {
            $io->section(sprintf('Verarbeite Feed: %s (ID: %d)', $feed->getName(), $feed->getId()));

            try {
                $stats = $this->importer->import($feed->getId());

                $io->success(sprintf(
                    'Erfolgreich! Verarbeitet: %d | Neu: %d | Aktualisiert: %d | Blacklisted: %d | Gelöscht: %d',
                    $stats['processed'], $stats['inserted'], $stats['updated'], $stats['blacklisted'], $stats['deleted']
                ));
            } catch (\Exception $e) {
                $io->error(sprintf('Fehler beim Verarbeiten von Feed "%s": %s', $feed->getName(), $e->getMessage()));
            }
        }

        $io->success('Alle Feeds wurden verarbeitet.');
        return Command::SUCCESS;
    }
}
