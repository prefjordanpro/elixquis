<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextType, SubmitType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('firstname', TextType::class, ['label' => 'Prénom'])
            ->add('lastname', TextType::class, ['label' => 'Nom'])
            ->add('submit', SubmitType::class, ['label' => 'Enregistrer', 'attr' => ['class' => 'btn btn-success']]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => User::class]); }
}
