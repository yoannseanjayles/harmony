<?php

namespace App\Form;

use App\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'project.form.title',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'project.title.required'),
                    new Length(min: 3, minMessage: 'project.title.min_length', max: 160),
                ],
                'attr' => [
                    'placeholder' => 'Roadmap Q3',
                ],
            ])
            ->add('provider', ChoiceType::class, [
                'label' => 'project.form.provider',
                'choices' => Project::providerChoices(),
                'constraints' => [
                    new Choice(choices: Project::providerValues(), message: 'project.provider.invalid'),
                ],
            ])
            ->add('model', ChoiceType::class, [
                'label' => 'project.form.model',
                'choices' => Project::modelChoices(),
                'constraints' => [
                    new Choice(choices: Project::modelValues(), message: 'project.model.invalid'),
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'project.form.status',
                'choices' => Project::statusChoices(),
                'constraints' => [
                    new Choice(choices: array_values(Project::statusChoices()), message: 'project.status.invalid'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Project::class,
            'translation_domain' => 'messages',
        ]);
    }
}
