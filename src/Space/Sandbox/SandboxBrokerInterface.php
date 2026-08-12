<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

interface SandboxBrokerInterface
{
    public function execute(SandboxExecutionRequest $request): SandboxExecutionResult;

    /**
     * @param string $runId
     *
     * @return 'cancellation_requested'|'terminal'
     */
    public function cancel(string $runId): string;
}
