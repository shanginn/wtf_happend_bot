<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflowInput;
use Bot\AgenticWorkflow\AgentPrompt;
use Bot\AgenticWorkflow\AgentRuntime;
use Bot\AgenticWorkflow\BotToolCatalog;
use Bot\AgenticWorkflow\TelegramAgentMessageMapper;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Bot\Llm\Tools\Chat\GetCurrentTimeExecutor;
use Bot\Llm\Tools\Chat\SearchMessagesExecutor;
use Bot\Llm\Tools\Runtime\ListRuntimeCapabilitiesExecutor;
use Bot\Llm\Tools\Runtime\RuntimeToolExecutor;
use Bot\Llm\Tools\Runtime\SetRuntimeCapabilityStatusExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeSkillExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeToolExecutor;
use Bot\Llm\Tools\Search\InternetSearchExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiSchemaExecutor;
use Bot\Memory\ParticipantMemoryStore;
use Bot\Telegram\InputMessageView;
use Cycle\ORM\ORMInterface;
use Mockery;
use Phenogram\Bindings\ClientInterface;
use PHPUnit\Framework\TestCase;
use PiPHP\Agent\CancellationToken;
use PiPHP\AI\Tool\ToolValidator;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use PiPHP\Temporal\Tool\DurableAgentToolInterface;
use PiPHP\Temporal\Tool\DurableToolExecutionContext;
use UnexpectedValueException;

final class PiPHPAgentRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPromptUsesOneAutonomousAgentAndExplicitTerminalActions(): void
    {
        $prompt = AgentPrompt::build("### concise\nKeep replies short.");

        self::assertStringContainsString('use tools as needed', $prompt);
        self::assertStringContainsString('telegram_api_call', $prompt);
        self::assertStringContainsString('stay_silent', $prompt);
        self::assertStringContainsString('commit_to_reply alone', $prompt);
        self::assertStringContainsString('Keep replies short.', $prompt);
        self::assertStringContainsString('call search_messages', $prompt);
        self::assertStringContainsString('relative_day', $prompt);
        self::assertStringContainsString('truncated=true', $prompt);
        self::assertStringContainsString('recall_memory', $prompt);
        self::assertStringContainsString('proves only', $prompt);
        self::assertStringNotContainsString('decision agent', strtolower($prompt));
    }

    public function testTelegramViewMapsToPortablePiMessage(): void
    {
        $message = TelegramAgentMessageMapper::map(new InputMessageView(
            text: "From: Alice\n\nText:\nWhat happened?",
            participantReference: 'telegram_user:7',
            imageAttachmentCount: 1,
            messageTimestamp: 1_710_000_000,
        ))->toArray();

        self::assertSame('user', $message['role']);
        self::assertSame('telegram_user:7', $message['name']);
        self::assertStringContainsString(
            'Participant reference: telegram_user:7',
            $message['content'][0]['text'],
        );
        self::assertStringContainsString(
            'Visual bytes are not included',
            $message['content'][0]['text'],
        );
        self::assertSame(1, $message['metadata']['imageAttachmentCount']);
        self::assertSame(1_710_000_000, $message['metadata']['telegramMessageTimestamp']);
        self::assertStringNotContainsString('api.telegram.org/file/bot', $message['content'][0]['text']);
    }

    public function testCatalogPublishesUniquePortableSchemas(): void
    {
        $validator = new ToolValidator();
        $names     = [];

        foreach (BotToolCatalog::definitions() as $tool) {
            $validator->assertSupportedSchema($tool);
            $names[] = $tool->name;
        }

        self::assertSame($names, array_values(array_unique($names)));
        self::assertContains('telegram_api_call', $names);
        self::assertContains('stay_silent', $names);
        self::assertContains('commit_to_reply', $names);
        self::assertContains('run_runtime_tool', $names);
        $wire = BotToolCatalog::wireDefinitions();
        self::assertCount(count($names), $wire);

        $wireByName = array_column($wire, null, 'name');
        self::assertSame('sequential', $wireByName['stay_silent']['executionMode']);
        self::assertSame('sequential', $wireByName['commit_to_reply']['executionMode']);
        self::assertSame('sequential', $wireByName['telegram_api_call']['executionMode']);
        self::assertSame('sequential', $wireByName['save_memory']['executionMode']);
        self::assertArrayNotHasKey('executionMode', $wireByName['internet_search']);
    }

    public function testStaySilentIsADurableTerminalTool(): void
    {
        $tool = $this->catalog()->registry()->get('stay_silent');
        self::assertInstanceOf(DurableAgentToolInterface::class, $tool);

        $result = $tool->executeDurably(
            new DurableToolExecutionContext(
                toolCallId: 'call-1',
                toolName: 'stay_silent',
                arguments: [],
                idempotencyKey: 'stable-key',
                metadata: ['chatId' => -100123],
            ),
            new CancellationToken(),
        );

        self::assertTrue($result->terminate);
        self::assertSame('The agent deliberately stayed silent.', $result->content[0]->text);
    }

    public function testReplyCommitmentIsDurableAndNonTerminal(): void
    {
        $tool = $this->catalog()->registry()->get('commit_to_reply');
        self::assertInstanceOf(DurableAgentToolInterface::class, $tool);

        $result = $tool->executeDurably(
            new DurableToolExecutionContext(
                toolCallId: 'call-1',
                toolName: 'commit_to_reply',
                arguments: [],
                idempotencyKey: 'stable-key',
                metadata: ['chatId' => -100123],
            ),
            new CancellationToken(),
        );

        self::assertFalse($result->terminate);
        self::assertStringContainsString('Reply commitment accepted', $result->content[0]->text);
    }

    public function testToolExecutionRequiresTrustedChatMetadata(): void
    {
        $tool = $this->catalog()->registry()->get('stay_silent');
        self::assertInstanceOf(DurableAgentToolInterface::class, $tool);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('chatId');

        $tool->executeDurably(
            new DurableToolExecutionContext('call-1', 'stay_silent', [], 'stable-key'),
            new CancellationToken(),
        );
    }

    public function testRuntimeSchemasFailClosedOnUnsupportedKeywords(): void
    {
        $error = RuntimeCapabilityValidator::parametersSchemaError([
            'type'                  => 'object',
            'properties'            => [],
            'unevaluatedProperties' => false,
        ]);

        self::assertNotNull($error);
        self::assertStringContainsString('Unsupported parameters_schema', $error);
    }

    public function testWorkflowInputCarriesOnlyPiWireState(): void
    {
        $input = new AgenticWorkflowInput(
            chatId: -100123,
            chatType: 'supergroup',
            model: AgentRuntime::MODEL,
            tools: BotToolCatalog::wireDefinitions(),
            messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]]],
            processedCount: 4,
            agentRun: 2,
            paused: true,
        );

        self::assertSame(AgentRuntime::MODEL, $input->model);
        self::assertSame(4, $input->processedCount);
        self::assertSame(2, $input->agentRun);
        self::assertTrue($input->paused);
    }

    private function catalog(): BotToolCatalog
    {
        $orm    = Mockery::mock(ORMInterface::class);
        $client = Mockery::mock(ClientInterface::class);
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);

        return new BotToolCatalog(
            memoryStore: new ParticipantMemoryStore($orm),
            searchMessages: new SearchMessagesExecutor($orm),
            internetSearch: new InternetSearchExecutor(baseUrl: 'http://searxng.test'),
            currentTime: new GetCurrentTimeExecutor(),
            telegramSchema: new TelegramApiSchemaExecutor(),
            telegramCall: new TelegramApiCallExecutor($client),
            listRuntimeCapabilities: new ListRuntimeCapabilitiesExecutor($orm),
            upsertRuntimeSkill: new UpsertRuntimeSkillExecutor($orm),
            upsertRuntimeTool: new UpsertRuntimeToolExecutor($orm),
            setRuntimeCapabilityStatus: new SetRuntimeCapabilityStatusExecutor($orm),
            runtimeTool: new RuntimeToolExecutor($orm, $models),
        );
    }
}
