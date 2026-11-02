<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Schützt alle Seiten des Feed-Managers mit dem Keycloak-Login.
 * Öffentlich bleiben nur der Feed-Export (für Google & Co.) und die Login-Routen.
 */
class KeycloakAuthSubscriber implements EventSubscriberInterface
{
    public const SESSION_USER = '_keycloak_user';
    public const SESSION_TARGET = '_keycloak_target_path';

    private const PUBLIC_ROUTES = [
        'app_feed_export',
        'app_feed_export_idealo',
        'app_keycloak_login',
        'app_keycloak_check',
        'app_keycloak_logout',
        'app_keycloak_logged_out',
    ];

    public function __construct(
        private KeycloakClient $keycloak,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Nach dem Router (32), damit die Route bekannt ist
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->keycloak->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if (in_array($route, self::PUBLIC_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }

        if (!$this->keycloak->isConfigured()) {
            $event->setResponse(new Response(
                'Der Login ist nicht konfiguriert. Bitte KEYCLOAK_URL, KEYCLOAK_REALM und KEYCLOAK_CLIENT_ID setzen.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/plain; charset=utf-8']
            ));
            return;
        }

        $session = $request->getSession();
        $user = $session->get(self::SESSION_USER);
        if (is_array($user) && ($user['expires_at'] ?? 0) > time()) {
            $request->attributes->set('_keycloak_user', $user);
            return;
        }
        $session->remove(self::SESSION_USER);

        // Nach dem Login zur ursprünglich aufgerufenen Seite zurück (nur bei GET sinnvoll)
        if ($request->isMethod('GET')) {
            $session->set(self::SESSION_TARGET, $request->getRequestUri());
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_keycloak_login')));
    }
}
