<?php

namespace App\Controller;

use App\Security\KeycloakAccessDeniedException;
use App\Security\KeycloakAuthSubscriber;
use App\Security\KeycloakClient;
use App\Security\KeycloakException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Login / Logout über Keycloak (OpenID Connect).
 */
class KeycloakController extends AbstractController
{
    private const SESSION_FLOW = '_keycloak_flow';

    public function __construct(private KeycloakClient $keycloak) {}

    #[Route('/login/keycloak', name: 'app_keycloak_login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        if (!$this->keycloak->isEnabled()) {
            return $this->redirectToRoute('app_feed_manager');
        }
        if (!$this->keycloak->isConfigured()) {
            return new Response('Der Login ist nicht konfiguriert.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $flow = [
            'state' => bin2hex(random_bytes(16)),
            'nonce' => bin2hex(random_bytes(16)),
            'verifier' => KeycloakClient::base64UrlEncode(random_bytes(48)),
            'created' => time(),
        ];
        $request->getSession()->set(self::SESSION_FLOW, $flow);

        return new RedirectResponse($this->keycloak->getAuthorizationUrl(
            $this->callbackUrl(),
            $flow['state'],
            $flow['nonce'],
            $flow['verifier']
        ));
    }

    #[Route('/login/keycloak/check', name: 'app_keycloak_check', methods: ['GET'])]
    public function check(Request $request): Response
    {
        $session = $request->getSession();
        $flow = $session->get(self::SESSION_FLOW);
        $session->remove(self::SESSION_FLOW);

        if ($request->query->has('error')) {
            return $this->loginError('Anmeldung abgebrochen: ' . $request->query->getString('error_description', $request->query->getString('error')));
        }

        $state = $request->query->getString('state');
        $code = $request->query->getString('code');
        if (!is_array($flow) || $state === '' || $code === '' || !hash_equals($flow['state'], $state)
            || ($flow['created'] ?? 0) < time() - 600) {
            // z.B. alter Tab / Zurück-Button -> einfach neu anmelden
            return $this->redirectToRoute('app_keycloak_login');
        }

        try {
            $user = $this->keycloak->authenticate($code, $this->callbackUrl(), $flow['verifier'], $flow['nonce']);
        } catch (KeycloakAccessDeniedException $e) {
            return $this->loginError($e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (KeycloakException $e) {
            return $this->loginError($e->getMessage());
        }

        // Neue Session-ID nach dem Login (Schutz gegen Session Fixation)
        $session->migrate(true);
        $session->set(KeycloakAuthSubscriber::SESSION_USER, $user);

        $target = (string) $session->get(KeycloakAuthSubscriber::SESSION_TARGET, '');
        $session->remove(KeycloakAuthSubscriber::SESSION_TARGET);
        // Nur interne Pfade zulassen (kein Open Redirect)
        if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            $target = $this->generateUrl('app_feed_manager');
        }

        return new RedirectResponse($target);
    }

    #[Route('/logout', name: 'app_keycloak_logout', methods: ['GET', 'POST'])]
    public function logout(Request $request): Response
    {
        $session = $request->getSession();
        $user = $session->get(KeycloakAuthSubscriber::SESSION_USER);
        $session->invalidate();

        if (!$this->keycloak->isEnabled() || !$this->keycloak->isConfigured()) {
            return $this->redirectToRoute('app_feed_manager');
        }

        return new RedirectResponse($this->keycloak->getLogoutUrl(
            $this->generateUrl('app_keycloak_logged_out', [], UrlGeneratorInterface::ABSOLUTE_URL),
            is_array($user) ? ($user['id_token'] ?? null) : null
        ));
    }

    #[Route('/logged-out', name: 'app_keycloak_logged_out', methods: ['GET'])]
    public function loggedOut(): Response
    {
        return new Response(
            '<!doctype html><meta charset="utf-8"><title>Abgemeldet</title>'
            . '<body style="font-family: sans-serif; padding: 40px;">'
            . '<p>Du wurdest abgemeldet.</p><p><a href="' . htmlspecialchars($this->generateUrl('app_feed_manager')) . '">Erneut anmelden</a></p>'
        );
    }

    private function callbackUrl(): string
    {
        return $this->keycloak->getRedirectUriOverride()
            ?? $this->generateUrl('app_keycloak_check', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function loginError(string $message, int $status = Response::HTTP_UNAUTHORIZED): Response
    {
        return new Response(
            '<!doctype html><meta charset="utf-8"><title>Anmeldung fehlgeschlagen</title>'
            . '<body style="font-family: sans-serif; padding: 40px;">'
            . '<p>' . htmlspecialchars($message) . '</p>'
            . '<p><a href="' . htmlspecialchars($this->generateUrl('app_keycloak_login')) . '">Erneut versuchen</a></p>',
            $status
        );
    }
}
