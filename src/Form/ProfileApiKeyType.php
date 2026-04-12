<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ProfileApiKeyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('apiKey', PasswordType::class, [
            'label' => 'profile.form.api_key',
            'mapped' => false,
            'always_empty' => true,
            'attr' => [
                'autocomplete' => 'off',
                'placeholder' => 'sk-...',
            ],
            'constraints' => [
                new NotBlank(['message' => 'profile.api_key.required']),
                new Length(min: 12, minMessage: 'profile.api_key.min_length'),
                new Regex(pattern: '/^\S+$/', message: 'profile.api_key.no_spaces'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }
}
