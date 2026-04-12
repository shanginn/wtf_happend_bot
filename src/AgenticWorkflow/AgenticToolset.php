<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Skills\ImageAnalysisSkill;
use Bot\Llm\Skills\QuestionAnsweringSkill;
use Bot\Llm\Skills\SummarizationSkill;
use Bot\Llm\Tools\Chat\CreatePoll;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Bot\Llm\Tools\Chat\SearchMessages;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;

final class AgenticToolset
{
    /** @var array<class-string> */
    public const array TOOLS = [
        RespondDecision::class,
        SaveMemory::class,
        RecallMemory::class,
        SearchMessages::class,
        CreatePoll::class,
        GetCurrentTime::class,
    ];

    /** @var array<class-string> */
    public const array SKILLS = [
        SummarizationSkill::class,
        QuestionAnsweringSkill::class,
        ImageAnalysisSkill::class,
    ];
}
