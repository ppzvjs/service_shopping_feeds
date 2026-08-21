<?php
namespace App\Form;

use App\Entity\Mysql\FreeShippingRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FreeShippingRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('skuPattern', TextType::class, [
            'label' => 'Artikelnummer oder Wildcard (z.B. BU-30010*)'
        ]);
    }

    public function configureOptions(OptionsResolver $optionsResolver): void
    {
        $optionsResolver->setDefaults(['data_class' => FreeShippingRule::class]);
    }
}
