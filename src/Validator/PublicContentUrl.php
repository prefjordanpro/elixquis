<?php
namespace App\Validator;
use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class PublicContentUrl extends Constraint
{
    public string $message = 'Utilisez une URL http(s) valide ou un chemin local commençant par un seul /, sans espaces.';
}
