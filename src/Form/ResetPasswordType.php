<?php
namespace App\Form;
use Symfony\Component\Form\{AbstractType, FormBuilderInterface};
use Symfony\Component\Form\Extension\Core\Type\{PasswordType, RepeatedType};
use Symfony\Component\Validator\Constraints\{Length, NotBlank};

final class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Les deux mots de passe doivent être identiques.',
            'options' => ['attr' => ['autocomplete' => 'new-password', 'minlength' => 12, 'maxlength' => 128]],
            'first_options' => ['label' => 'Nouveau mot de passe'],
            'second_options' => ['label' => 'Confirmer le mot de passe'],
            'constraints' => [new NotBlank(), new Length(min: 12, max: 128)],
        ]);
    }
}
