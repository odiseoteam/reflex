<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\QuestionFormFactory;
use App\Mcp\Elicitation\Question;
use App\Mcp\Elicitation\QuestionBox;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class QuestionFormFactoryTest extends KernelTestCase
{
    public function testTheBoxCancelsNewQuestionsAndReplaysAnsweredOnes(): void
    {
        $request = ElicitRequest::forForm('Which label?', new ElicitationSchema(['label' => new StringSchemaDefinition('Label')], ['label']));
        $box = new QuestionBox();

        self::assertSame(ElicitAction::Cancel, $box($request)->action);
        $question = $box->takeAsked();
        self::assertNotNull($question);
        self::assertNull($box->takeAsked());

        $box->load([$question->key => ['label' => 'bug']]);
        $answer = $box($request);
        self::assertSame(ElicitAction::Accept, $answer->action);
        self::assertSame(['label' => 'bug'], $answer->content);
        self::assertNull($box->takeAsked());

        self::assertSame(ElicitAction::Decline, $box(ElicitRequest::forUrl('Sign in there', 'https://example.com'))->action);
    }

    public function testTheFormFollowsTheSchemaAndReturnsTypedContent(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'email' => ['type' => 'string', 'format' => 'email'],
            'tags' => ['type' => 'array', 'items' => ['enum' => ['a', 'b', 'c']], 'maxItems' => 2],
            'ratio' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            'size' => ['type' => 'string', 'oneOf' => [['const' => 's', 'title' => 'Small'], ['const' => 'l', 'title' => 'Large']]],
        ], 'required' => ['email']];
        $question = new Question(Question::keyFor('Details?', $schema), 'Details?', $schema);
        $forms = self::getContainer()->get(QuestionFormFactory::class);

        $form = $forms->create($question, ['csrf_protection' => false]);
        $form->submit(['email' => 'not an email', 'tags' => ['a', 'b', 'c']]);
        self::assertFalse($form->isValid());
        self::assertFalse($form->get('email')->isValid());
        self::assertFalse($form->get('tags')->isValid());
        self::assertTrue($form->get('ratio')->isValid());

        $form = $forms->create($question, ['csrf_protection' => false]);
        $form->submit(['email' => 'ann@example.com', 'tags' => ['b'], 'ratio' => '0.5', 'size' => 'l']);
        self::assertTrue($form->isValid());
        self::assertSame(['email' => 'ann@example.com', 'tags' => ['b'], 'ratio' => 0.5, 'size' => 'l'], $forms->content($question, $form));
    }
}
