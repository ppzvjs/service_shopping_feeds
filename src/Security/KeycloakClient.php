<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Schlanker OpenID-Connect-Client für Keycloak (Authorization Code Flow + PKCE).
 *
 * Bewusst ohne zusätzliche Composer-Pakete umgesetzt. Der Code wird serverseitig
 * direkt am Token-Endpunkt von Keycloak (über HTTPS) gegen Tokens getauscht; laut
 * OpenID-Connect-Spezifikation reicht in diesem Fall die TLS-Prüfung des Servers,
 * zusätzlich werden Issuer, Audience, Ablaufzeit und Nonce des ID-Tokens geprüft.
 */
class KeycloakClient
{
    /** Wie lange eine Anmeldung im Feed-Manager gültig bleibt (Sekunden) */
    public const LOGIN_TTL = 8 * 3600;

    public function __construct(
        #[Autowire(env: 'bool:default:keycloak_enabled_default:KEYCLOAK_ENABLED')]
        private bool $enabled,
        #[Autowire(env: 'default::KEYCLOAK_URL')]
        private ?string $baseUrl,
        #[Autowire(env: 'default::KEYCLOAK_REALM')]
        private ?string $realm,
        #[Autowire(env: 'default::KEYCLOAK_CLIENT_ID')]
        private ?string $clientId,
        #[Autowire(env: 'default::KEYCLOAK_CLIENT_SECRET')]
        private ?string $clientSecret,
        #[Autowire(env: 'default::KEYCLOAK_REQUIRED_ROLE')]
        private ?string $requiredRole,
        #[Autowire(env: 'default::KEYCLOAK_REDIRECT_URI')]
        private ?string $redirectUriOverride,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->baseUrl) !== ''
            && trim((string) $this->realm) !== ''
            && trim((string) $this->clientId) !== '';
    }

    public function getRedirectUriOverride(): ?string
    {
        $uri = trim((string) $this->redirectUriOverride);

        return $uri !== '' ? $uri : null;
    }

    public function getIssuer(): string
    {
        return rtrim((string) $this->baseUrl, '/') . '/realms/' . rawurlencode((string) $this->realm);
    }

    private function endpoint(string $name): string
    {
        return $this->getIssuer() . '/protocol/openid-connect/' . $name;
    }

    public function getAuthorizationUrl(string $redirectUri, string $state, string $nonce, string $codeVerifier): string
    {
        return $this->endpoint('auth') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function getLogoutUrl(string $postLogoutRedirectUri, ?string $idTokenHint): string
    {
        $params = [
            'client_id' => $this->clientId,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
        ];
        if ($idTokenHint) {
            $params['id_token_hint'] = $idTokenHint;
        }

        return $this->endpoint('logout') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Tauscht den Authorization Code gegen Tokens und liefert die geprüften Benutzerdaten.
     *
     * @return array{sub: string, username: string, name: string, email: ?string, roles: string[], id_token: string, expires_at: int}
     */
    public function authenticate(string $code, string $redirectUri, string $codeVerifier, string $expectedNonce): array
    {
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $codeVerifier,
        ];
        if (trim((string) $this->clientSecret) !== '') {
            $params['client_secret'] = $this->clientSecret;
        }

        $tokens = $this->postForm($this->endpoint('token'), $params);
        if (empty($tokens['id_token']) || empty($tokens['access_token'])) {
            throw new KeycloakException('Keycloak hat keine gültigen Tokens geliefert.');
        }

        $idToken = self::decodeJwtPayload($tokens['id_token']);
        $now = time();

        if (($idToken['iss'] ?? null) !== $this->getIssuer()) {
            throw new KeycloakException('Ungültiger Token-Aussteller (iss).');
        }
        $aud = (array) ($idToken['aud'] ?? []);
        if (!in_array($this->clientId, $aud, true)) {
            throw new KeycloakException('Token ist nicht für diese Anwendung ausgestellt (aud).');
        }
        if (!isset($idToken['exp']) || (int) $idToken['exp'] < $now - 60) {
            throw new KeycloakException('Token ist abgelaufen.');
        }
        if (!hash_equals($expectedNonce, (string) ($idToken['nonce'] ?? ''))) {
            throw new KeycloakException('Ungültige Nonce im Token.');
        }
        if (empty($idToken['sub'])) {
            throw new KeycloakException('Token enthält keine Benutzerkennung.');
        }

        // Rollen stehen im Access Token (Realm-Rollen und Client-Rollen dieses Clients)
        $accessToken = self::decodeJwtPayload($tokens['access_token']);
        $roles = array_merge(
            (array) ($accessToken['realm_access']['roles'] ?? []),
            (array) ($accessToken['resource_access'][$this->clientId]['roles'] ?? [])
        );
        $roles = array_values(array_unique(array_map('strval', $roles)));

        $required = trim((string) $this->requiredRole);
        if ($required !== '' && !in_array($required, $roles, true)) {
            throw new KeycloakAccessDeniedException(sprintf('Für den Feed-Manager fehlt die Keycloak-Rolle "%s".', $required));
        }

        $username = (string) ($idToken['preferred_username'] ?? $accessToken['preferred_username'] ?? $idToken['sub']);

        return [
            'sub' => (string) $idToken['sub'],
            'username' => $username,
            'name' => (string) ($idToken['name'] ?? $username),
            'email' => isset($idToken['email']) ? (string) $idToken['email'] : null,
            'roles' => $roles,
            'id_token' => (string) $tokens['id_token'],
            'expires_at' => $now + self::LOGIN_TTL,
        ];
    }

    private function postForm(string $url, array $params): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => http_build_query($params, '', '&'),
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new KeycloakException('Keycloak ist nicht erreichbar.');
        }

        $data = json_decode($body, true);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($status !== 200 || !is_array($data)) {
            $error = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? '') : '';
            throw new KeycloakException(trim('Anmeldung bei Keycloak fehlgeschlagen. ' . $error));
        }

        return $data;
    }

    private static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            throw new KeycloakException('Ungültiges Token-Format.');
        }
        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        if (!is_array($payload)) {
            throw new KeycloakException('Ungültiger Token-Inhalt.');
        }

        return $payload;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
