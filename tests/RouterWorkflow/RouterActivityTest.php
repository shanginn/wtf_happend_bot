<?php

declare(strict_types=1);

namespace Tests\RouterWorkflow;

use Bot\Llm\Skills\ImageAnalysisSkill;
use Bot\Llm\Skills\QuestionAnsweringSkill;
use Bot\Llm\Skills\SummarizationSkill;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
use Bot\RouterWorkflow\RouterActivity;
use Tests\TestCase;

class RouterActivityTest extends TestCase
{
    public function testSystemPromptContainsIdentity(): void
    {
        $prompt = RouterActivity::systemPrompt();

        self::assertStringContainsString('AI assistant', $prompt);
        self::assertStringContainsString('Telegram', $prompt);
    }

    public function testSystemPromptContainsResponsePolicy(): void
    {
        $prompt = RouterActivity::systemPrompt();

        self::assertStringContainsString('response_policy', $prompt);
        self::assertStringContainsString('decision was made', $prompt);
    }

    public function testSystemPromptContainsMemoryUsage(): void
    {
        $prompt = RouterActivity::systemPrompt();

        self::assertStringContainsString('memory_usage', $prompt);
        self::assertStringContainsString('persistent memory', $prompt);
    }

    public function testSystemPromptContainsDate(): void
    {
        $prompt = RouterActivity::systemPrompt();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        self::assertStringContainsString($today, $prompt);
    }

    public function testBuildSkillsPromptEmpty(): void
    {
        self::assertSame('', RouterActivity::buildSkillsPrompt([]));
    }

    public function testBuildSkillsPromptWithSkills(): void
    {
        $prompt = RouterActivity::buildSkillsPrompt([
            SummarizationSkill::class,
            QuestionAnsweringSkill::class,
            ImageAnalysisSkill::class,
        ]);

        self::assertStringContainsString('available_skills', $prompt);
        self::assertStringContainsString('summarization', $prompt);
        self::assertStringContainsString('question-answering', $prompt);
        self::assertStringContainsString('image-analysis', $prompt);
    }

    public function testBuildToolsPromptEmpty(): void
    {
        self::assertSame('', RouterActivity::buildToolsPrompt([]));
    }

    public function testBuildToolsPromptWithTools(): void
    {
        $prompt = RouterActivity::buildToolsPrompt([
            SaveMemory::class,
            RecallMemory::class,
        ]);

        self::assertStringContainsString('available_tools', $prompt);
        self::assertStringContainsString('save_memory', $prompt);
        self::assertStringContainsString('recall_memory', $prompt);
    }
}
