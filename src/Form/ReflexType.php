<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Connection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ReflexInput>
 */
final class ReflexType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('triggerSource', ChoiceType::class, [
                'label' => 'Webhook source',
                'choices' => array_combine(ReflexInput::SOURCES, ReflexInput::SOURCES),
                'help' => 'Events arrive at POST /webhook/{source}.',
            ])
            ->add('triggerEvent', TextType::class, [
                'label' => 'Event type',
                'help' => 'E.g. "issue.opened", or "*" for any event of the source.',
            ])
            ->add('instructions', TextareaType::class, [
                'attr' => ['rows' => 8, 'placeholder' => 'Classify new issues, look for duplicates and comment when you find one.'],
                'help' => 'What the agent should do when the trigger fires.',
            ])
            ->add('model', ChoiceType::class, [
                'choices' => array_combine(ReflexInput::MODELS, ReflexInput::MODELS),
            ])
            ->add('connections', EntityType::class, [
                'class' => Connection::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Systems whose tools the agent may use.',
            ])
            ->add('enabled', CheckboxType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReflexInput::class]);
    }
}
