<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Agent\OpenaiMessageTransformer;
use Bot\Entity\LlmProviderResponse;
use Bot\Entity\LlmProviderResponse\LlmProviderResponseRepository;
use Bot\Entity\ParticipantMemory;
use Bot\Entity\UpdateRecord;
use Bot\Entity\UpdateRecord\UpdateRecordRepository;
use Bot\Llm\Skills\RelevantMemoriesSkill;
use Bot\Llm\ProviderHistory\LlmProviderType;
use Bot\Llm\Skills\SkillInterface;
use Bot\Telegram\Factory;
use Bot\Telegram\TelegramFileUrlResolver;
use Bot\Telegram\TelegramFileUrlResolverInterface;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Carbon\CarbonInterval;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\Openai;
use Shanginn\Openai\Openai\OpenaiSerializer;
use Shanginn\Openai\Openai\OpenaiSerializerInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

#[ActivityInterface(prefix: 'Agentic.')]
class AgenticActivity
{
    private const int HISTORY_LIMIT = 50;

    private readonly Agent $agent;
    private readonly SerializerInterface $telegramSerializer;
    private readonly OpenaiSerializerInterface $openaiSerializer;

    public function __construct(
        Openai $openai,
        ApiInterface $api,
        private readonly ORMInterface $orm,
        ?TelegramFileUrlResolverInterface $fileUrlResolver = null,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
        ?OpenaiMessageTransformer $updateTransformer = null,
        ?SerializerInterface $telegramSerializer = null,
        ?OpenaiSerializerInterface $openaiSerializer = null,
    ) {
        $fileUrlResolver ??= new TelegramFileUrlResolver($api);
        $updateViewFactory ??= new TelegramUpdateViewFactory($fileUrlResolver);
        $this->agent = new Agent($openai, $updateViewFactory, $updateTransformer);
        $this->telegramSerializer = $telegramSerializer ?? new Serializer(new Factory());
        $this->openaiSerializer = $openaiSerializer ?? new OpenaiSerializer();
    }

    public static function getDefinition(): ActivityProxy|self
    {
        return Workflow::newActivityStub(
            self::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
        );
    }

    /**
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    #[ActivityMethod]
    public function complete(
        int $chatId,
        ?int $topicId,
        array $tools = [],
        array $skills = [],
    ): ErrorResponse|CompletionResponse {
        $history = $this->loadHistory($chatId);
        $result = $this->agent->complete(
            history: $history,
            tools: $tools,
            skills: $skills,
        );

        if ($result instanceof CompletionResponse && isset($result->choices[0])) {
            $this->saveResponseMessage(
                chatId: $chatId,
                topicId: $topicId,
                message: $result->choices[0]->message,
                rawResponse: $result,
            );
        }

        return $result;
    }

    #[ActivityMethod]
    public function memoryComplete(
        array $memory,
        array $tools = [],
        array $skills = [],
    ): ErrorResponse|CompletionResponse {
        return $this->agent->complete(
            history: $memory,
            tools: $tools,
            skills: $skills,
        );
    }

    #[ActivityMethod]
    public function recollectRelevantMemories(int $chatId, array $history): ErrorResponse|CompletionResponse
    {
        /** @var \Bot\Entity\ParticipantMemory\ParticipantMemoryRepository $repo */
        $repo = $this->orm->getRepository(ParticipantMemory::class);
        $records = $repo->findByChatId($chatId);

        $lines = ['All participant memories:'];

        foreach ($records as $memory) {
            $lines[] = sprintf(
                '- %s | memory: %s | quote: %s | context: %s | updated: %s',
                $memory->participantLabel,
                $memory->memory,
                $memory->quote,
                $memory->context,
                date('Y-m-d', $memory->updatedAt),
            );
        }

        return $this->agent->recollectRelevantMemories(
            history: $history,
            allMemories: implode("\n", $lines),
            skills: [RelevantMemoriesSkill::class],
        );
    }

    #[ActivityMethod]
    public function respondFromMemory(
        array $memory,
        array $tools = [],
        array $skills = [],
    ): ErrorResponse|CompletionResponse {
        return $this->agent->respond(
            history: $memory,
            tools: $tools,
            skills: $skills,
        );
    }

    /**
     * @return array<MessageInterface>
     */
    private function loadHistory(int $chatId): array
    {
        $timeline = [
            ...$this->loadUpdateMessages($chatId),
            ...$this->loadResponseMessages($chatId),
        ];

        usort(
            $timeline,
            static fn (array $left, array $right): int => [$left['createdAt'], $left['sourceOrder'], $left['sequence']]
                <=> [$right['createdAt'], $right['sourceOrder'], $right['sequence']],
        );

        return array_map(
            static fn (array $item): MessageInterface => $item['message'],
            $timeline,
        );
    }

    /**
     * @return array<array{createdAt: int, sourceOrder: int, sequence: int, message: MessageInterface}>
     */
    private function loadUpdateMessages(int $chatId): array
    {
        /** @var UpdateRecordRepository $repo */
        $repo = $this->orm->getRepository(UpdateRecord::class);

        $items = [];

        foreach ($repo->findLastN($chatId, self::HISTORY_LIMIT) as $record) {
            $decoded = json_decode($record->update, true, flags: \JSON_THROW_ON_ERROR);
            $update = $this->telegramSerializer->deserialize($decoded, UpdateInterface::class);
            $message = $this->agent->transformUpdates([$update])[0] ?? null;

            if (!$message instanceof MessageInterface) {
                continue;
            }

            $items[] = [
                'createdAt' => $record->createdAt > 0 ? $record->createdAt : self::extractUpdateTimestamp($decoded),
                'sourceOrder' => 0,
                'sequence' => $record->updateId,
                'message' => $message,
            ];
        }

        return $items;
    }

    /**
     * @return array<array{createdAt: int, sourceOrder: int, sequence: int, message: MessageInterface}>
     */
    private function loadResponseMessages(int $chatId): array
    {
        /** @var LlmProviderResponseRepository $repo */
        $repo = $this->orm->getRepository(LlmProviderResponse::class);

        $items = [];

        foreach ($repo->findLastNByChat($chatId, LlmProviderType::Openai, self::HISTORY_LIMIT) as $record) {
            $message = $this->deserializeResponseMessage($record);

            $items[] = [
                'createdAt' => $record->createdAt,
                'sourceOrder' => 1,
                'sequence' => $record->id,
                'message' => $message,
            ];
        }

        return $items;
    }

    private function deserializeResponseMessage(LlmProviderResponse $record): MessageInterface
    {
        return match ($record->type) {
            LlmProviderType::Openai => $this->deserializeOpenaiMessage($record),
            default => throw new \RuntimeException(sprintf(
                'Unsupported LLM provider type: %s',
                $record->type->value,
            )),
        };
    }

    private function deserializeOpenaiMessage(LlmProviderResponse $record): MessageInterface
    {
        $message = $this->openaiSerializer->deserialize(
            serialized: $record->payload,
            to: $record->messageClass,
            tools: AgenticToolset::TOOLS,
        );

        if (!$message instanceof MessageInterface) {
            throw new \RuntimeException(sprintf(
                'Stored payload %s did not deserialize into %s.',
                $record->messageClass,
                MessageInterface::class,
            ));
        }

        return $message;
    }

    #[ActivityMethod]
    public function saveResponseMessage(
        int $chatId,
        ?int $topicId,
        MessageInterface $message,
        ?CompletionResponse $rawResponse = null,
    ): true {
        /** @var LlmProviderResponseRepository $repo */
        $repo = $this->orm->getRepository(LlmProviderResponse::class);

        $repo->save(new LlmProviderResponse(
            chatId: $chatId,
            topicId: $topicId,
            type: LlmProviderType::Openai,
            messageClass: $message::class,
            payload: $this->openaiSerializer->serialize($message),
            rawResponse: $rawResponse === null ? null : $this->openaiSerializer->serialize($rawResponse),
            createdAt: time(),
        ));

        return true;
    }

    private static function extractUpdateTimestamp(array $decodedUpdate): int
    {
        $paths = [
            ['message', 'date'],
            ['edited_message', 'date'],
            ['channel_post', 'date'],
            ['edited_channel_post', 'date'],
            ['business_message', 'date'],
            ['edited_business_message', 'date'],
            ['callback_query', 'message', 'date'],
        ];

        foreach ($paths as $path) {
            $value = self::valueAtPath($decodedUpdate, $path);
            if (is_int($value)) {
                return $value;
            }
        }

        return 0;
    }

    private static function valueAtPath(array $payload, array $path): mixed
    {
        $cursor = $payload;

        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function toUserMessageView(UpdateInterface $update)
    {

    }
}
