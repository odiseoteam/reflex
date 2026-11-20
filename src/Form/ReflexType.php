<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Connection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
                'label' => 'Trigger',
                'choices' => array_combine(ReflexInput::SOURCES, ReflexInput::SOURCES),
                'help' => 'A webhook source (events arrive at POST /webhook/{source}), a schedule, a poll of an MCP tool, an absence (X without Y), or an MCP subscription: the server says a resource changed.',
            ])
            ->add('triggerEvent', TextType::class, [
                'label' => 'Event type',
                'help' => 'For webhooks: e.g. "issue.opened", or "*" for any event of the source.',
            ])
            ->add('cron', TextType::class, [
                'label' => 'Schedule (cron, UTC)',
                'required' => false,
                'attr' => ['placeholder' => '0 9 * * 1'],
                'help' => 'For "schedule": when it runs, e.g. "0 9 * * 1" for Mondays at 09:00 UTC.',
            ])
            ->add('pollConnection', EntityType::class, [
                'class' => Connection::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Pick a connection',
                'label' => 'Connection',
            ])
            ->add('pollTool', TextType::class, [
                'label' => 'Tool',
                'required' => false,
                'attr' => ['placeholder' => 'list_issues'],
                'help' => 'Its name on the server. It must be automatic in the policy: nobody approves a poll.',
            ])
            ->add('pollArguments', TextareaType::class, [
                'label' => 'Arguments (JSON)',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => '{"state": "open"}'],
            ])
            ->add('pollIntervalMinutes', IntegerType::class, ['label' => 'Every (minutes)'])
            ->add('pollItemKey', TextType::class, [
                'label' => 'Item id field',
                'required' => false,
                'help' => 'Runs on items with an id not seen before. Empty to run whenever the result changes.',
            ])
            ->add('subscriptionConnection', EntityType::class, [
                'class' => Connection::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Pick a connection',
                'label' => 'Connection',
                'help' => 'Its server must speak MCP 2026-07-28 and support resource subscriptions.',
            ])
            ->add('subscriptionUris', TextareaType::class, [
                'label' => 'Resources (one URI per line)',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'tracker://issues/42'],
                'help' => 'Runs when the server says one of them changed; the run gets its new contents.',
            ])
            ->add('subscriptionListChanged', CheckboxType::class, [
                'label' => 'Also when the server\'s list of resources changes',
                'required' => false,
            ])
            ->add('absenceSource', ChoiceType::class, [
                'label' => 'Webhook source',
                'choices' => array_combine(ReflexInput::WEBHOOK_SOURCES, ReflexInput::WEBHOOK_SOURCES),
            ])
            ->add('absenceWhen', TextType::class, [
                'label' => 'When this happens',
                'required' => false,
                'attr' => ['placeholder' => 'email_captured'],
            ])
            ->add('absenceMissing', TextType::class, [
                'label' => 'and this doesn\'t follow',
                'required' => false,
                'attr' => ['placeholder' => 'order_completed'],
            ])
            ->add('absenceWithinMinutes', IntegerType::class, ['label' => 'within (minutes)'])
            ->add('absenceCorrelation', TextType::class, [
                'label' => 'for the same',
                'required' => false,
                'attr' => ['placeholder' => 'customer.email'],
                'help' => 'Dot path into both payloads.',
            ])
            ->add('instructions', TextareaType::class, [
                'attr' => ['rows' => 8, 'placeholder' => 'Classify new issues, look for duplicates and comment when you find one.'],
                'help' => 'What the agent should do when the trigger fires. Markdown works.',
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
            ->add('dailyTokenBudget', IntegerType::class, [
                'label' => 'Daily token budget',
                'required' => false,
                'help' => 'Runs are skipped once spent, until midnight UTC. Empty for no limit.',
            ])
            ->add('maxToolCalls', IntegerType::class, ['label' => 'Max tool calls per run'])
            ->add('timeLimitSeconds', IntegerType::class, ['label' => 'Time limit per run (seconds)'])
            ->add('enabled', CheckboxType::class, [
                'required' => false,
                'help' => \sprintf('Disabled automatically after %d failed runs in a row.', \App\Entity\Reflex::FAILURE_THRESHOLD),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReflexInput::class]);
    }
}
