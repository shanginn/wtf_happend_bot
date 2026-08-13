<?php

declare(strict_types=1);

namespace Bot\Space\Publication;

use PiPHP\Temporal\Contract\ToolExecutionGatewayInterface;
use PiPHP\Temporal\DTO\ToolActivityInput;
use PiPHP\Temporal\DTO\ToolActivityResult;

/** Converts only expected publication refusals into model-visible tool errors. */
final readonly class SpaceCapabilityPublicationRejectionGateway implements ToolExecutionGatewayInterface
{
    public function __construct(private ToolExecutionGatewayInterface $inner) {}

    public function execute(ToolActivityInput $input): ToolActivityResult
    {
        try {
            return $this->inner->execute($input);
        } catch (SpaceCapabilityPublicationRejected $rejection) {
            if ($input->name !== SpaceCapabilityPublicationTool::NAME) {
                throw $rejection;
            }

            return new ToolActivityResult(
                callId: $input->callId,
                name: $input->name,
                content: [['type' => 'text', 'text' => $rejection->getMessage()]],
                isError: true,
                metadata: [
                    ...$input->metadata,
                    'idempotencyKey'      => $input->idempotencyKey,
                    'publicationRejected' => true,
                ],
            );
        }
    }
}
