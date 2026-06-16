<?php

namespace App\Controller;

use App\Entity\Mysql\FeedConfig;
use App\Entity\Mysql\FeedBlackList;
use App\Entity\Mysql\FreeShippingRule;
use App\Entity\Mysql\ShippingRule;
use App\Form\FeedConfigType;
use App\Form\FeedBlacklistType;
use App\Form\FreeShippingRuleType;
use App\Form\ShippingRuleType;
use App\Service\FeedImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/feed-manager')]
class FeedManagerController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $mysqlEntityManager
    ) {}

    #[Route('', name: 'app_feed_manager', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        // 1. Feed Config (Gibt es schon einen Eintrag? Wenn nicht, neu anlegen)
        $config = $this->mysqlEntityManager->getRepository(FeedConfig::class)->findOneBy([]) ?? new FeedConfig();
        $configForm = $this->createForm(FeedConfigType::class, $config);
        $configForm->handleRequest($request);

        if ($configForm->isSubmitted() && $configForm->isValid()) {
            $this->mysqlEntityManager->persist($config);
            $this->mysqlEntityManager->flush();
            $this->addFlash('success', 'Feed-URL aktualisiert.');
            return $this->redirectToRoute('app_feed_manager');
        }

        // 2. Blacklist Form
        $blacklistEntry = new FeedBlacklist();
        $blacklistForm = $this->createForm(FeedBlacklistType::class, $blacklistEntry);
        $blacklistForm->handleRequest($request);

        if ($blacklistForm->isSubmitted() && $blacklistForm->isValid()) {
            $this->mysqlEntityManager->persist($blacklistEntry);
            $this->mysqlEntityManager->flush();
            $this->addFlash('success', 'Artikelnummer blockiert.');
            return $this->redirectToRoute('app_feed_manager');
        }

        // 3. Shipping Rule Form
        $shippingRule = new ShippingRule();
        $shippingForm = $this->createForm(ShippingRuleType::class, $shippingRule);
        $shippingForm->handleRequest($request);

        if ($shippingForm->isSubmitted() && $shippingForm->isValid()) {
            $this->mysqlEntityManager->persist($shippingRule);
            $this->mysqlEntityManager->flush();
            $this->addFlash('success', 'Versandkosten-Regel hinzugefügt.');
            return $this->redirectToRoute('app_feed_manager');
        }

        // 4. NEU: Free Shipping Form
        $freeShippingRule = new FreeShippingRule();
        $freeShippingForm = $this->createForm(FreeShippingRuleType::class, $freeShippingRule);
        $freeShippingForm->handleRequest($request);

        if ($freeShippingForm->isSubmitted() && $freeShippingForm->isValid()) {
            $this->mysqlEntityManager->persist($freeShippingRule);
            $this->mysqlEntityManager->flush();
            $this->addFlash('success', 'Versandkosten-Ausnahme hinzugefügt.');
            return $this->redirectToRoute('app_feed_manager');
        }

        // Daten für Twig laden
        $blacklist = $this->mysqlEntityManager->getRepository(FeedBlacklist::class)->findAll();
        $shippingRules = $this->mysqlEntityManager->getRepository(ShippingRule::class)->findBy([], ['minPrice' => 'ASC']);
        $freeShippingRules = $this->mysqlEntityManager->getRepository(FreeShippingRule::class)->findAll(); // NEU

        return $this->render('feed_manager/index.html.twig', [
            'configForm' => $configForm->createView(),
            'blacklistForm' => $blacklistForm->createView(),
            'shippingForm' => $shippingForm->createView(),
            'freeShippingForm' => $freeShippingForm->createView(), // NEU
            'blacklist' => $blacklist,
            'shippingRules' => $shippingRules,
            'freeShippingRules' => $freeShippingRules, // NEU
            'currentUrl' => $config->getFeedUrl()
        ]);
    }

    #[Route('/delete-blacklist/{id}', name: 'app_feed_delete_blacklist', methods: ['POST'])]
    public function deleteBlacklist(FeedBlacklist $item): Response
    {
        $this->mysqlEntityManager->remove($item);
        $this->mysqlEntityManager->flush();
        $this->addFlash('success', 'Eintrag aus Blacklist entfernt.');
        return $this->redirectToRoute('app_feed_manager');
    }

    #[Route('/delete-shipping/{id}', name: 'app_feed_delete_shipping', methods: ['POST'])]
    public function deleteShipping(ShippingRule $rule): Response
    {
        $this->mysqlEntityManager->remove($rule);
        $this->mysqlEntityManager->flush();
        $this->addFlash('success', 'Versandregel entfernt.');
        return $this->redirectToRoute('app_feed_manager');
    }

    #[Route('/run-import', name: 'app_feed_run_import', methods: ['POST'])]
    public function runImport(FeedImporter $feedImporter): Response
    {
        try {
            // Hier rufen wir den soeben erstellten Service auf
            $stats = $feedImporter->import();

            $this->addFlash('success', sprintf(
                'Synchronisierung erfolgreich! %d Artikel im Feed verarbeitet (%d neu, %d aktualisiert). %d Blacklist-Treffer ignoriert. %d alte Artikel aus DB gelöscht.',
                $stats['processed'],
                $stats['inserted'],
                $stats['updated'],
                $stats['blacklisted'],
                $stats['deleted']
            ));

        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Import: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_feed_manager');
    }

    #[Route('/delete-free-shipping/{id}', name: 'app_feed_delete_free_shipping', methods: ['POST'])]
    public function deleteFreeShipping(FreeShippingRule $rule): Response
    {
        $this->mysqlEntityManager->remove($rule);
        $this->mysqlEntityManager->flush();
        $this->addFlash('success', 'Ausnahme entfernt.');
        return $this->redirectToRoute('app_feed_manager');
    }
}
