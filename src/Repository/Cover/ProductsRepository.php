<?php

namespace App\Repository\Cover;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Cover\Products;

/**
 * @extends ServiceEntityRepository<Products>
 */
class ProductsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Products::class);
    }

    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.productsMetas', 'md')      // like LEFT JOIN BUMANAUS
            ->leftJoin('p.productsC2Warts', 'c2w')   // like LEFT JOIN C2WART
            ->leftJoin('p.productsBuAufTxts', 'bat') // LEFT JOIN BUAUFTXT
            ->andWhere('c2w.shop_status = :status')
            ->setParameter('status', 1)
            ->addSelect('md', 'c2w','bat');

        if (isset($criteria['artikel_nr'])) {
            $qb->andWhere('trim(md.artikel_nr) = :artikel_nr')
                ->setParameter('artikel_nr', $criteria['artikel_nr']);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        if ($orderBy) {
            foreach ($orderBy as $field => $direction) {
                $qb->addOrderBy("p.$field", $direction);
            }
        }

        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return Products[] Returns an array of Products objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Products
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
