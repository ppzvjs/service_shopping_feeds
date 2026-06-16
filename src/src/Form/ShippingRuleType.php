<?php

namespace App\Form;

use App\Entity\Mysql\ShippingRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ShippingRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('minPrice', NumberType::class, ['label' => 'Ab Produktpreis (€)'])
            ->add('shippingCost', NumberType::class, ['label' => 'Neue Versandkosten (€)']);
    }

    public function configureOptions(OptionsResolver $optionsResolver): void
    {
        $optionsResolver->setDefaults(['data_class' => ShippingRule::class]);
    }
}
