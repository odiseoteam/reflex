<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\Decision;
use App\Entity\DecisionKind;
use App\Entity\Precedent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\User;
use App\Entity\Verdict;
use App\Entity\Vote;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\Bridge\Doctrine\EntityVectorDocument;
use Symfony\AI\Store\RetrieverInterface;

/**
 * Remembers what people decided and recalls the closest precedents of a reflex for a new case.
 * Without the embeddings server, decisions are still kept and runs go on without memory.
 */
final readonly class Memory
{
    /** Precedents a run is given at most. */
    public const int RECALL_LIMIT = 3;

    /**
     * Cosine distance past which a precedent is too unlike the case to help. multilingual-e5-small
     * packs distances close: similar cases measured under 0.08, unrelated ones over 0.11.
     */
    public const float MAX_DISTANCE = 0.09;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RetrieverInterface $precedents,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Keeps a person's final word on a decision; the embedding is computed on flush.
     *
     * @param string|null $question what the server asked, read before answering clears it
     */
    public function record(Decision $decision, Verdict $verdict, User $user, ?string $question = null): Precedent
    {
        $approvals = array_values(array_filter($decision->getVotes()->toArray(), static fn (Vote $vote): bool => $vote->isApprove()));
        $decidedBy = Verdict::Approved === $verdict && [] !== $approvals
            ? implode(', ', array_map(static fn (Vote $vote): string => $vote->getUser()->getName(), $approvals))
            : $user->getName();

        $notes = Verdict::Approved === $verdict
            ? array_filter(array_map(static fn (Vote $vote): ?string => null === $vote->getNote() ? null : $vote->getUser()->getName().': '.$vote->getNote(), $approvals))
            : array_filter([$decision->getNote()]);

        $run = $decision->getRun();
        $answers = $decision->getAnswers();
        $precedent = new Precedent(
            $run->getReflex(),
            $decision,
            $decision->getKind(),
            $verdict,
            $decision->getToolName(),
            $decision->getArguments(),
            $decision->getReason(),
            mb_substr($decidedBy, 0, 255),
            [] === $notes ? null : implode("\n", $notes),
            Verdict::Answered === $verdict && [] !== $answers ? array_values($answers)[\count($answers) - 1] : null,
            $run->getEvent(),
            DecisionKind::Question === $decision->getKind() ? $question : null,
        );

        $this->entityManager->persist($precedent);
        $this->entityManager->flush();

        return $precedent;
    }

    /**
     * The precedents closest to the event that started the run.
     *
     * @return list<Recollection>
     */
    public function recall(Run $run): array
    {
        return $this->search($run->getReflex(), Precedent::situation($run->getEvent()));
    }

    /**
     * The precedents closest to a pending decision, to show next to it.
     *
     * @return list<Recollection>
     */
    public function recallFor(Decision $decision): array
    {
        $situation = Precedent::situation($decision->getRun()->getEvent(), $decision->getToolName(), $decision->getArguments(), $decision->getReason(), $decision->getQuestion()?->message);

        return array_values(array_filter(
            $this->search($decision->getRun()->getReflex(), $situation),
            static fn (Recollection $recollection): bool => $recollection->precedent->getDecision() !== $decision,
        ));
    }

    /**
     * @return list<Recollection>
     */
    private function search(Reflex $reflex, string $situation): array
    {
        try {
            $documents = $this->precedents->retrieve(Precedent::embeddable($situation), [
                'where' => 'e.reflex = :reflex',
                'params' => ['reflex' => $reflex],
                'limit' => self::RECALL_LIMIT,
                'maxScore' => self::MAX_DISTANCE,
            ]);

            $recollections = [];
            foreach ($documents as $document) {
                $precedent = $document instanceof EntityVectorDocument ? $document->getEntity() : null;
                if ($precedent instanceof Precedent) {
                    $recollections[] = new Recollection($precedent, (float) $document->getScore());
                }
            }

            return $recollections;
        } catch (\Throwable $e) {
            $this->logger->warning('Memory unavailable, going on without precedents: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
    }
}
