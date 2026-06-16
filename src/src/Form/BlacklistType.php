<?php

namespace App\Form;

use App\Entity\Blacklist;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BlacklistType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sku', TextType::class, [
                'label' => 'Artikelnummer zum Ausschließen',
                'attr' => ['placeholder' => 'z.B. ART-12345']
            ])
            ->add('save', SubmitType::class, ['label' => 'Ausschließen']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Blacklist::class,
        ]);
    }
}
