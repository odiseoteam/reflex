<?php

declare(strict_types=1);

namespace App\Form;

use App\Mcp\Elicitation\Question;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Builds a form from the flat schema a server sends with its question.
 */
final readonly class QuestionFormFactory
{
    public function __construct(
        private FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $options options of the whole form
     *
     * @return FormInterface<array<string, mixed>|null>
     */
    public function create(Question $question, array $options = []): FormInterface
    {
        $builder = $this->formFactory->createNamedBuilder('answer', FormType::class, null, $options);
        $required = $question->required();

        foreach ($question->properties() as $name => $property) {
            [$type, $options] = self::field($property, \in_array($name, $required, true));
            $options['label'] = \is_string($property['title'] ?? null) ? $property['title'] : $name;
            if (\is_string($property['description'] ?? null)) {
                $options['help'] = $property['description'];
            }
            if (\array_key_exists('default', $property)) {
                $options['data'] = $property['default'];
            }
            $builder->add($name, $type, $options);
        }

        /** @var FormInterface<array<string, mixed>|null> $form */
        $form = $builder->getForm();

        return $form;
    }

    /**
     * What the server receives: only answered fields, in the types the schema names.
     *
     * @param FormInterface<array<string, mixed>|null> $form
     *
     * @return array<string, mixed>
     */
    public function content(Question $question, FormInterface $form): array
    {
        $data = $form->getData() ?? [];
        $content = [];
        foreach ($question->properties() as $name => $property) {
            $value = $data[$name] ?? null;
            if (null === $value || '' === $value || [] === $value) {
                continue;
            }
            $content[$name] = match ($property['type'] ?? null) {
                'integer' => is_numeric($value) ? (int) $value : $value,
                'number' => is_numeric($value) ? (float) $value : $value,
                'boolean' => (bool) $value,
                default => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value,
            };
        }

        return $content;
    }

    /**
     * @param array<mixed> $property
     *
     * @return array{0: class-string<FormTypeInterface<mixed>>, 1: array<string, mixed>}
     */
    private static function field(array $property, bool $required): array
    {
        $constraints = $required ? [new Assert\NotBlank()] : [];
        $options = ['required' => $required];

        $choices = self::choices($property);
        if (null !== $choices) {
            return [ChoiceType::class, $options + ['choices' => $choices, 'constraints' => $constraints]];
        }

        $items = \is_array($property['items'] ?? null) ? $property['items'] : [];
        if ('array' === ($property['type'] ?? null) && null !== $choices = self::choices($items)) {
            $constraints[] = new Assert\Count(min: self::bound($property, 'minItems', 0), max: self::bound($property, 'maxItems', 0));

            return [ChoiceType::class, $options + ['choices' => $choices, 'multiple' => true, 'expanded' => true, 'constraints' => $constraints]];
        }

        return match ($property['type'] ?? 'string') {
            'boolean' => [CheckboxType::class, ['required' => false]],
            'integer' => [IntegerType::class, $options + ['constraints' => [...$constraints, ...self::range($property)]]],
            'number' => [NumberType::class, $options + ['scale' => 6, 'constraints' => [...$constraints, ...self::range($property)]]],
            default => match ($property['format'] ?? null) {
                'email' => [EmailType::class, $options + ['constraints' => [...$constraints, new Assert\Email(), ...self::length($property)]]],
                'uri' => [UrlType::class, $options + ['default_protocol' => null, 'constraints' => [...$constraints, new Assert\Url(requireTld: true), ...self::length($property)]]],
                'date' => [DateType::class, $options + ['widget' => 'single_text', 'input' => 'string', 'input_format' => 'Y-m-d', 'constraints' => $constraints]],
                default => [TextType::class, $options + ['constraints' => [...$constraints, ...self::length($property)]]],
            },
        };
    }

    /**
     * @param array<mixed> $property
     *
     * @return array<string, string|int|float>|null label => value
     */
    private static function choices(array $property): ?array
    {
        $titles = \is_array($property['enumNames'] ?? null) ? $property['enumNames'] : [];
        if (\is_array($property['enum'] ?? null)) {
            $choices = [];
            foreach (array_values($property['enum']) as $i => $value) {
                if (\is_scalar($value) && !\is_bool($value)) {
                    $choices[\is_string($titles[$i] ?? null) ? $titles[$i] : (string) $value] = $value;
                }
            }

            return $choices;
        }

        $options = $property['oneOf'] ?? $property['anyOf'] ?? null;
        if (!\is_array($options)) {
            return null;
        }
        $choices = [];
        foreach ($options as $option) {
            $value = \is_array($option) ? ($option['const'] ?? null) : null;
            if (\is_string($value) || \is_int($value) || \is_float($value)) {
                $choices[\is_string($option['title'] ?? null) ? $option['title'] : (string) $value] = $value;
            }
        }

        return $choices;
    }

    /**
     * @param array<mixed> $property
     *
     * @return list<Constraint>
     */
    private static function length(array $property): array
    {
        $min = self::bound($property, 'minLength', 0);
        $max = self::bound($property, 'maxLength', 1);

        return null === $min && null === $max ? [] : [new Assert\Length(min: $min, max: $max)];
    }

    /**
     * @param array<mixed> $property
     *
     * @return list<Constraint>
     */
    private static function range(array $property): array
    {
        $min = \is_int($property['minimum'] ?? null) || \is_float($property['minimum'] ?? null) ? $property['minimum'] : null;
        $max = \is_int($property['maximum'] ?? null) || \is_float($property['maximum'] ?? null) ? $property['maximum'] : null;

        return null === $min && null === $max ? [] : [new Assert\Range(min: $min, max: $max)];
    }

    /**
     * @param array<mixed> $property
     * @param 0|1          $floor
     *
     * @return ($floor is 0 ? int<0, max>|null : int<1, max>|null)
     */
    private static function bound(array $property, string $key, int $floor): ?int
    {
        $value = $property[$key] ?? null;

        return \is_int($value) && $value >= $floor ? $value : null;
    }
}
