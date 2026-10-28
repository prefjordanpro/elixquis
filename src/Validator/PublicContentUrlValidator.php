<?php
namespace App\Validator;
use Symfony\Component\Validator\{Constraint, ConstraintValidator};

final class PublicContentUrlValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($value === null || $value === '') { return; }
        $safeCharacters = is_string($value) && !preg_match('~[\x00-\x20\\\\]~', $value);
        $local = $safeCharacters && str_starts_with($value, '/') && !str_starts_with($value, '//');
        $absolute = $safeCharacters && preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL);
        if (!$local && !$absolute) { $this->context->buildViolation($constraint->message)->addViolation(); }
    }
}
