<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Skills\ImageAnalysisSkill;
use Bot\Llm\Skills\MemoryManagementSkill;
use Bot\Llm\Skills\QuestionAnsweringSkill;
use Bot\Llm\Skills\RelevantMemoriesSkill;
use Bot\Llm\Skills\SummarizationSkill;
use Bot\Llm\Tools\Chat\CreatePoll;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Bot\Llm\Tools\Chat\SearchMessages;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Llm\Tools\Memory\ForgetMemory;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
use Bot\Llm\Tools\Memory\UpdateMemory;
use Bot\Llm\Tools\Telegram\TelegramApiCall;
use Bot\Llm\Tools\Telegram\TelegramApiSchema;

final class AgenticToolset
{
    /** @var array<class-string> */
    public const array TOOLS = [
        RespondDecision::class,
        SaveMemory::class,
        RecallMemory::class,
        UpdateMemory::class,
        ForgetMemory::class,
        SearchMessages::class,
        CreatePoll::class,
        GetCurrentTime::class,
        TelegramApiSchema::class,
        TelegramApiCall::class,
    ];

    /** @var array<class-string> */
    public const array DECISION_TOOLS = [
        RespondDecision::class,
        SaveMemory::class,
    ];

    /** @var array<class-string> */
    public const array MEMORY_TOOLS = [
        SaveMemory::class,
        RecallMemory::class,
        UpdateMemory::class,
        ForgetMemory::class,
    ];

    /** @var array<class-string> */
    public const array SKILLS = [
        MemoryManagementSkill::class,
        RelevantMemoriesSkill::class,
        SummarizationSkill::class,
        QuestionAnsweringSkill::class,
        ImageAnalysisSkill::class,
    ];

    /** @var array<class-string> */
    public const array RESPONSE_TOOLS = [
        SaveMemory::class,
        RecallMemory::class,
        UpdateMemory::class,
        ForgetMemory::class,
        SearchMessages::class,
        GetCurrentTime::class,
        TelegramApiSchema::class,
        TelegramApiCall::class,
    ];

    /** @var array<class-string> */
    public const array RESPONSE_SKILLS = [
        MemoryManagementSkill::class,
        SummarizationSkill::class,
        QuestionAnsweringSkill::class,
        ImageAnalysisSkill::class,
    ];
}
