<?php

declare(strict_types=1);

namespace App\Mcp\Elicitation;

use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

/**
 * Answers a server's questions without waiting for a person: a question answered before
 * gets its stored answer, a new one is cancelled and kept, so the call can be repeated
 * once someone answers it.
 */
final class QuestionBox implements ElicitationCallbackInterface
{
    private ?Question $asked = null;

    /**
     * @param array<string, array<string, mixed>> $answers question key => content
     */
    public function __construct(
        private array $answers = [],
    ) {
    }

    public function __invoke(ElicitRequest $request): ElicitResult
    {
        // Only forms are supported; a URL can't be opened on someone's behalf.
        if (ElicitationMode::Form !== $request->mode) {
            return new ElicitResult(ElicitAction::Decline);
        }

        $question = Question::fromRequest($request);
        if (isset($this->answers[$question->key])) {
            return new ElicitResult(ElicitAction::Accept, $this->answers[$question->key]);
        }

        $this->asked ??= $question;

        return new ElicitResult(ElicitAction::Cancel);
    }

    /**
     * @param array<string, array<string, mixed>> $answers
     */
    public function load(array $answers): void
    {
        $this->answers = $answers;
        $this->asked = null;
    }

    /**
     * The first unanswered question since the last call, if any.
     */
    public function takeAsked(): ?Question
    {
        $asked = $this->asked;
        $this->asked = null;

        return $asked;
    }
}
