<?php

namespace App\Security;

/**
 * Anmeldung war erfolgreich, aber dem Benutzer fehlt die benötigte Rolle.
 */
class KeycloakAccessDeniedException extends KeycloakException
{
}
