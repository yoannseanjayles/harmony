<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'registration.form.email',
                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => 'lead@harmony.app',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'app.user.email.required']),
                    new Email(['message' => 'app.user.email.invalid']),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'app.user.password.mismatch',
                'first_options' => [
                    'label' => 'registration.form.password',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Au moins 12 caracteres',
                    ],
                ],
                'second_options' => [
                    'label' => 'registration.form.password_repeat',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Retapez votre mot de passe',
                    ],
                ],
                'constraints' => [
                    new NotBlank(['message' => 'app.user.password.required']),
                    new Length(min: 12, minMessage: 'app.user.password.min_length'),
                    new Regex(pattern: '/[A-Z]/', message: 'app.user.password.uppercase'),
                    new Regex(pattern: '/[a-z]/', message: 'app.user.password.lowercase'),
                    new Regex(pattern: '/\d/', message: 'app.user.password.number'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'translation_domain' => 'messages',
        ]);
    }
}
