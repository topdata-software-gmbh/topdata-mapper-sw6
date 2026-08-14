<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * Thrown when a matching DSL string is invalid (grammar, unknown field or
 * dimension, pairing-matrix violation). Carries structured context so the
 * settings page can render a precise error (shop field, dimension, position).
 *
 * 08/2026 created
 */
class DslParseException extends \InvalidArgumentException
{
    /**
     * @param int|null $position 0-based character position in the DSL string (null when not applicable)
     */
    public function __construct(
        string $message,
        public readonly ?string $shopField = null,
        public readonly ?string $dimension = null,
        public readonly ?int $position = null,
    ) {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return [
            'message'   => $this->getMessage(),
            'shopField' => $this->shopField,
            'dimension' => $this->dimension,
            'position'  => $this->position,
        ];
    }
}