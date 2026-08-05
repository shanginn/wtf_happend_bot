<?php

declare(strict_types=1);

namespace Bot\Activity;

use Bot\Entity\UpdateRecord;
use Bot\Entity\UpdateRecord\UpdateRecordRepository;
use Bot\Telegram\InputMessageView;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Throwable;
use UnexpectedValueException;

#[ActivityInterface(prefix: 'Telegram.')]
class TelegramActivity
{
    private SerializerInterface $serializer;
    private TelegramUpdateViewFactory $updateViewFactory;

    public function __construct(
        private ORMInterface $orm,
        private EntityManagerInterface $em,
        ?SerializerInterface $serializer = null,
    ) {
        $this->serializer        = $serializer ?? new Serializer();
        $this->updateViewFactory = new TelegramUpdateViewFactory();
    }

    public static function getDefinition(): ActivityProxy|self
    {
        return Workflow::newActivityStub(
            self::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minutes(1))
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withBackoffCoefficient(2.5)
                        ->withInitialInterval(20)
                        ->withMaximumAttempts(3),
                )
        );
    }

    #[ActivityMethod]
    public function saveUpdates(
        Update $update,
        int $workflowChatId,
        string $ingestionRunId,
    ): bool {
        /** @var UpdateRecordRepository $repo */
        $repo = $this->orm->getRepository(UpdateRecord::class);

        $existing = $repo->find($update->updateId);
        if ($existing !== null) {
            return $existing->ingestionRunId === $ingestionRunId;
        }

        try {
            $serialized = $this->serializer->serialize([$update]);
            $payload    = $serialized[0] ?? null;
            if (!is_array($payload)) {
                throw new UnexpectedValueException(
                    sprintf('Telegram update %d did not serialize to an object.', $update->updateId),
                );
            }

            $encoded = json_encode(
                $payload,
                \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
            );
            if (!is_string($encoded)) {
                throw new UnexpectedValueException(
                    sprintf('Telegram update %d did not encode to JSON.', $update->updateId),
                );
            }
        } catch (Throwable $failure) {
            throw new ApplicationFailure(
                message: sprintf(
                    'Telegram update %d cannot be serialized for durable ingestion.',
                    $update->updateId,
                ),
                type: 'telegram-update-serialization',
                nonRetryable: true,
                previous: $failure,
            );
        }

        $record = new UpdateRecord(
            updateId: $update->updateId,
            update: $encoded,
            chatId: $workflowChatId,
            topicId: $update->effectiveMessage?->messageThreadId,
            createdAt: $update->effectiveMessage?->date ?? time(),
            ingestionRunId: $ingestionRunId,
        );

        $this->em->persist($record);
        $this->em->run();

        return true;
    }

    #[ActivityMethod]
    public function updateToView(UpdateInterface $update): InputMessageView
    {
        try {
            return $this->updateViewFactory->create($update);
        } catch (Throwable $failure) {
            throw new ApplicationFailure(
                message: sprintf(
                    'Telegram update %d cannot be converted to an agent message.',
                    $update->updateId,
                ),
                type: 'telegram-update-view',
                nonRetryable: true,
                previous: $failure,
            );
        }
    }
}
