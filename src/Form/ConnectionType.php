<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ConnectionAuth;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ConnectionInput>
 */
final class ConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'help' => 'Also the prefix of its tools, e.g. "GitHub" gives github_create_issue.',
            ])
            ->add('url', UrlType::class, [
                'label' => 'Server URL',
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'https://mcp.example.com/mcp'],
            ])
            ->add('auth', EnumType::class, [
                'class' => ConnectionAuth::class,
                'label' => 'Authentication',
                'choice_label' => static fn (ConnectionAuth $auth): string => $auth->label(),
                'expanded' => true,
            ])
            ->add('token', PasswordType::class, [
                'label' => 'Bearer token',
                'required' => false,
            ])
            ->add('oauthClientId', TextType::class, [
                'label' => 'Client ID',
                'required' => false,
                'help' => 'Only for servers without dynamic client registration, such as GitHub.',
            ])
            ->add('oauthClientSecret', PasswordType::class, [
                'label' => 'Client secret',
                'required' => false,
            ])
            ->add('oauthIssuer', UrlType::class, [
                'label' => 'Authorization server issuer',
                'required' => false,
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'https://github.com/login/oauth'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ConnectionInput::class]);
    }
}
