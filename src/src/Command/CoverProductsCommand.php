<?php

namespace App\Command;

use App\Entity\Cover\Products;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cover:products',
    description: 'Add a short description for your command',
)]
class CoverProductsCommand extends Command
{

    private ManagerRegistry $registry;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct();
        $this->registry = $registry;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $con = $this->registry->getManager('cover');
        $products = $con->getRepository(Products::class)->findAll();

        $io->text(count($products));
        return Command::SUCCESS;
    }
}
