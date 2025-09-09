<?php

namespace App\Form;

use App\Entity\Address;
use App\Entity\Carrier;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class OrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Adresses (radios)
            ->add('addresses', EntityType::class, [
                'label'       => "Choisissez votre adresse de livraison",
                'class'       => Address::class,
                'choices'     => $options['addresses'] ?? [],
                'expanded'    => true,
                'multiple'    => false,
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank(message: 'Sélectionnez une adresse.')],
            ])
            // Transporteur (radios)
            ->add('carriers', EntityType::class, [
                'label'       => "Choisissez votre transporteur",
                'class'       => Carrier::class,
                'expanded'    => true,
                'multiple'    => false,
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank(message: 'Sélectionnez un transporteur.')],
            ])
            
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,   // formulaire "libre"
            'addresses'  => [],     // évite null
        ]);
        $resolver->setAllowedTypes('addresses', ['array', \Traversable::class]);
    }
}
