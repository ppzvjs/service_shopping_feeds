<?php

namespace App\Controller;

use App\Entity\Mysql\FeedConfig;
use App\Entity\Mysql\FeedBlackList;
use App\Entity\Mysql\FeedOverrideSale; // Bestätigt
use App\Entity\Mysql\FeedWhiteList;
use App\Entity\Mysql\ShippingRule;
use App\Entity\Mysql\FreeShippingRule;
use App\Entity\Mysql\Product;
use App\Service\FeedImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/')]
class FeedManagerController extends AbstractController
{
    #[Route('', name: 'app_feed_manager')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        // Alle Feeds für die Auswahl/Tabs laden
        $allFeeds = $em->getRepository(FeedConfig::class)->findAll();

        // Aktiven Feed bestimmen (entweder aus der URL oder den ersten aus der DB)
        $activeFeedId = $request->query->get('active_feed');
        $activeFeed = null;

        if ($activeFeedId) {
            $activeFeed = $em->getRepository(FeedConfig::class)->find($activeFeedId);
        } elseif (!empty($allFeeds)) {
            $activeFeed = $allFeeds[0];
        }

        // --- FORMULAR A: NEUEN FEED HINZUFÜGEN ---
        $newFeed = new FeedConfig();
        $addFeedForm = $this->container->get('form.factory')->createNamedBuilder('add_feed_form', FormType::class, $newFeed)
            ->add('name', TextType::class, ['label' => 'Feed-Name (z.B. DE / AT)'])
            ->add('feedUrl', UrlType::class, ['label' => 'Google Shopping XML URL'])
            ->add('minProductPrice', NumberType::class, ['label' => 'Min. Preis (€)', 'required' => false])
            ->add('maxProductPrice', NumberType::class, ['label' => 'Max. Preis (€)', 'required' => false])
            ->getForm();

        $addFeedForm->handleRequest($request);
        if ($addFeedForm->isSubmitted() && $addFeedForm->isValid()) {
            $em->persist($newFeed);
            $em->flush();
            $this->addFlash('success', 'Neuer Feed wurde erfolgreich angelegt.');
            return $this->redirectToRoute('app_feed_manager', ['active_feed' => $newFeed->getId()]);
        }

        // Falls noch gar kein Feed existiert, zeigen wir nur die "Feed anlegen"-Maske
        if (!$activeFeed) {
            return $this->render('feed_manager/index.html.twig', [
                'allFeeds' => [],
                'activeFeed' => null,
                'addFeedForm' => $addFeedForm->createView(),
            ]);
        }

        // --- FORMULAR B: BESTEHENDEN FEED BEARBEITEN (ERWEITERT um Checkbox) ---
        $configForm = $this->container->get('form.factory')->createNamedBuilder('config_form', FormType::class, $activeFeed)
            ->add('feedUrl', UrlType::class, ['label' => 'URL bearbeiten'])
            ->add('minProductPrice', NumberType::class, ['label' => 'Min. Preis (€)', 'required' => false])
            ->add('maxProductPrice', NumberType::class, ['label' => 'Max. Preis (€)', 'required' => false])
            ->add('excludeAllProducts', CheckboxType::class, [
                'label' => 'Alle Artikel standardmäßig ausschließen (Whitelist-Modus aktivieren)',
                'required' => false,
            ])
            ->getForm();

        $configForm->handleRequest($request);
        if ($configForm->isSubmitted() && $configForm->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Feed-Einstellungen wurden aktualisiert.');
            return $this->redirectToRoute('app_feed_manager', ['active_feed' => $activeFeed->getId()]);
        }

        // --- FORMULAR C: BLACKLIST FÜR AKTIVEN FEED ---
        $blacklist = new FeedBlackList();
        $blacklistForm = $this->container->get('form.factory')->createNamedBuilder('blacklist_form', FormType::class, $blacklist)
            ->add('sku', TextType::class, ['label' => 'SKU / ID oder Wildcard (z.B. BU-*)'])
            ->getForm();

        $blacklistForm->handleRequest($request);
        if ($blacklistForm->isSubmitted() && $blacklistForm->isValid()) {
            $blacklist->setFeed($activeFeed);
            $em->persist($blacklist);
            $em->flush();
            $this->addFlash('success', 'Artikelsperre für diesen Feed hinzugefügt.');
            return $this->redirectToRoute('app_feed_manager', ['active_feed' => $activeFeed->getId()]);
        }

        // --- FORMULAR C2: WHITELIST FÜR AKTIVEN FEED ---
        $whitelist = new FeedWhiteList();
        $whitelistForm = $this->container->get('form.factory')->createNamedBuilder('whitelist_form', FormType::class, $whitelist)
            ->add('sku', TextType::class, ['label' => 'SKU / ID oder Wildcard (z.B. CH-* / 12345)'])
            ->getForm();

        $whitelistForm->handleRequest($request);
        if ($whitelistForm->isSubmitted() && $whitelistForm->isValid()) {
            $whitelist->setFeed($activeFeed);
            $em->persist($whitelist);
            $em->flush();
            $this->addFlash('success', 'Whitelist-Ausnahme (Artikel wieder einschließen) hinzugefügt.');
            return $this->redirectToRoute('app_feed_manager', ['active_feed' => $activeFeed->getId()]);
        }

        // --- NEU: FORMULAR F: SALE OVERRIDE FÜR AKTIVEN FEED ---
        $overrideSale = new FeedOverrideSale();
        $overrideSaleForm = $this->container->get('form.factory')->createNamedBuilder('override_sale_form', FormType::class, $overrideSale)
            ->add('sku', TextType::class, ['label' => 'SKU / ID oder Wildcard (z.B. SALE-*)'])
            ->getForm();

        $overrideSaleForm->handleRequest($request);
        if ($overrideSaleForm->isSubmitted() && $overrideSaleForm->isValid()) {
            $overrideSale->setFeed($activeFeed);
            $em->persist($overrideSale);
            $em->flush();
            $this->addFlash('success', 'Sale-Override-Regel für diesen Feed hinzugefügt.');
            return $this->redirectToRoute('app_feed_manager', ['active_feed' => $activeFeed->getId()]);
        }

        // --- FORMULAR D: VERSANDKOSTEN-REGEL FÜR AKTIVEN FEED ---
        $shippingRule = new ShippingRule();
        $shippingForm = $this->container->get('form.factory')->createNamedBuilder('shipping_form', FormType::class, $shippingRule)
            ->add('minPrice', NumberType::class, ['label' => 'Ab Preis (€)'])
            ->add('shippingCost', NumberType::class, ['label' => 'Versandkosten (€)'])
            ->getForm();

        $shippingForm->handleRequest($request);
        if ($shippingForm->isSubmitted() && $shippingForm->isValid()) {
            $shippingRule->setFeed($activeFeed);
            $em->persist($shippingRule);
            $em->flush();
            $this->addFlash('success', 'Versandregel für diesen Feed hinzugefügt.');
            return $this->redirectToRoute('app_feed_manager', ['active_feed' => $activeFeed->getId()]);
        }

        // --- FORMULAR E: GRATIS VERSAND FÜR AKTIVEN FEED ---
        $freeShipping = new FreeShippingRule();
        $freeShippingForm = $this->container->get('form.factory')->createNamedBuilder('free_shipping_form', FormType::class, $freeShipping)
            ->add('skuPattern', TextType::class, ['label' => 'Ausnahme SKU / Wildcard'])
            ->getForm();

        $freeShippingForm->handleRequest($request);
        if ($freeShippingForm->isSubmitted() && $freeShippingForm->isValid()) {
            $freeShipping->setFeed($activeFeed);
            $em->persist($freeShipping);
            $em->flush();
            $this->addFlash('success', 'Gratis-Versand-Ausnahme hinzugefügt.');
            return $this->redirectToRoute('app_feed_manager', ['active_feed' => $activeFeed->getId()]);
        }

        // Daten filtriert nach dem aktiven Feed für die Listen laden
        $blacklistEntries = $em->getRepository(FeedBlackList::class)->findBy(['feed' => $activeFeed]);
        $whitelistEntries = $em->getRepository(FeedWhiteList::class)->findBy(['feed' => $activeFeed]);
        $overrideSaleEntries = $em->getRepository(FeedOverrideSale::class)->findBy(['feed' => $activeFeed]); // NEU geladen
        $shippingRules = $em->getRepository(ShippingRule::class)->findBy(['feed' => $activeFeed], ['minPrice' => 'DESC']);
        $freeShippingRules = $em->getRepository(FreeShippingRule::class)->findBy(['feed' => $activeFeed]);

        // Aktuelle Produktanzahl in der DB für diesen Feed zählen
        $productCount = $em->getRepository(Product::class)->count(['feed' => $activeFeed]);

        return $this->render('feed_manager/index.html.twig', [
            'allFeeds' => $allFeeds,
            'activeFeed' => $activeFeed,
            'addFeedForm' => $addFeedForm->createView(),
            'configForm' => $configForm->createView(),
            'blacklistForm' => $blacklistForm->createView(),
            'whitelistForm' => $whitelistForm->createView(),
            'overrideSaleForm' => $overrideSaleForm->createView(), // NEU übergeben
            'shippingForm' => $shippingForm->createView(),
            'freeShippingForm' => $freeShippingForm->createView(),
            'blacklist' => $blacklistEntries,
            'whitelist' => $whitelistEntries,
            'overrideSales' => $overrideSaleEntries, // NEU übergeben
            'shippingRules' => $shippingRules,
            'freeShippingRules' => $freeShippingRules,
            'productCount' => $productCount,
        ]);
    }

    #[Route('run/{id}', name: 'app_feed_run_import', methods: ['POST'])]
    public function runImport(int $id, FeedImporter $importer, Request $request): Response
    {
        try {
            $stats = $importer->import($id);

            // Werte für die Infoboxen dauerhaft für diesen Feed in der Session merken
            $session = $request->getSession();
            $session->set('last_sync_total_' . $id, $stats['processed'] + $stats['blacklisted']);

            $this->addFlash('success', sprintf(
                'Import erfolgreich! Verarbeitet: %d | Neu: %d | Aktualisiert: %d | Blacklisted: %d | Gelöscht: %d',
                $stats['processed'], $stats['inserted'], $stats['updated'], $stats['blacklisted'], $stats['deleted']
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Import: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_feed_manager', ['active_feed' => $id]);
    }

    #[Route('delete-blacklist/{id}', name: 'app_feed_delete_blacklist', methods: ['POST'])]
    public function deleteBlacklist(FeedBlackList $item, EntityManagerInterface $em): Response
    {
        $feedId = $item->getFeed()->getId();
        $em->remove($item);
        $em->flush();
        $this->addFlash('success', 'Sperrung aufgehoben.');
        return $this->redirectToRoute('app_feed_manager', ['active_feed' => $feedId]);
    }

    #[Route('delete-whitelist/{id}', name: 'app_feed_delete_whitelist', methods: ['POST'])]
    public function deleteWhitelist(FeedWhiteList $item, EntityManagerInterface $em): Response
    {
        $feedId = $item->getFeed()->getId();
        $em->remove($item);
        $em->flush();
        $this->addFlash('success', 'Whitelist-Ausnahme entfernt.');
        return $this->redirectToRoute('app_feed_manager', ['active_feed' => $feedId]);
    }

    #[Route('delete-shipping/{id}', name: 'app_feed_delete_shipping', methods: ['POST'])]
    public function deleteShipping(ShippingRule $rule, EntityManagerInterface $em): Response
    {
        $feedId = $rule->getFeed()->getId();
        $em->remove($rule);
        $em->flush();
        $this->addFlash('success', 'Versandregel entfernt.');
        return $this->redirectToRoute('app_feed_manager', ['active_feed' => $feedId]);
    }

    #[Route('delete-free-shipping/{id}', name: 'app_feed_delete_free_shipping', methods: ['POST'])]
    public function deleteFreeShipping(FreeShippingRule $rule, EntityManagerInterface $em): Response
    {
        $feedId = $rule->getFeed()->getId();
        $em->remove($rule);
        $em->flush();
        $this->addFlash('success', 'Gratis-Versand-Ausnahme entfernt.');
        return $this->redirectToRoute('app_feed_manager', ['active_feed' => $feedId]);
    }

    #[Route('delete-feed/{id}', name: 'app_feed_delete', methods: ['POST'])]
    public function deleteFeed(FeedConfig $feed, EntityManagerInterface $em): Response
    {
        $em->remove($feed);
        $em->flush();

        $this->addFlash('success', sprintf('Der Feed "%s" und alle zugehörigen Daten wurden gelöscht.', $feed->getName()));
        return $this->redirectToRoute('app_feed_manager');
    }

    #[Route('delete-override-sale/{id}', name: 'app_feed_delete_override_sale', methods: ['POST'])]
    public function deleteOverrideSale(FeedOverrideSale $item, EntityManagerInterface $em): Response
    {
        $feedId = $item->getFeed()->getId();
        $em->remove($item);
        $em->flush();
        $this->addFlash('success', 'Sale-Override-Regel entfernt.');
        return $this->redirectToRoute('app_feed_manager', ['active_feed' => $feedId]);
    }
}
