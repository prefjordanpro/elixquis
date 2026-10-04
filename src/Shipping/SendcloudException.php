<?php
declare(strict_types=1);
namespace App\Shipping;

/** Ne conserve ni réponse brute, ni exception HTTP susceptible de contenir l’authentification. */
final class SendcloudException extends \DomainException {}
