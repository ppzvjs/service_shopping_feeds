<?php

namespace App\Command;

use App\Entity\Cover\Products;
use App\Mapper\GoogleMapper;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google:product',
    description: 'Add a short description for your command',
)]
class GoogleProductCommand extends Command
{

    public function __construct(private ManagerRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Google Product Command');
        $artikel_nr = $io->ask('Welche Artikelnummer?','30010086');

        $em = $this->registry->getManager('cover');
        $products = $em->getRepository(Products::class)->findBy(['artikel_nr' => $artikel_nr],[],100,0);
        $io->section('Gefundene Einträge: ' . count($products) . ' für die Artikelnummer ' . $artikel_nr);
        $googleMapper = new GoogleMapper();
        foreach($products as $product){
            $googleMapper->addProduct($product);
        }
        file_put_contents('/opt/feed/' . $artikel_nr . '.xml',$googleMapper->getFeed());
        return Command::SUCCESS;
    }
}
