<?php

declare(strict_types=1);

namespace Bot\Space\Publication;

use RuntimeException;

/** Expected, user-correctable refusal to publish a Space capability. */
final class SpaceCapabilityPublicationRejected extends RuntimeException {}
