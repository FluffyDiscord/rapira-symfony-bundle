<?php

namespace FluffyDiscord\RapiraBundle\HttpCache;

use Symfony\Component\HttpKernel\HttpCache\Store;
use Symfony\Contracts\Service\ResetInterface;

class ResettableStore extends Store implements ResetInterface
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $initialOptions;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $root,
        array  $options = [],
    )
    {
        parent::__construct($root, $options);

        $this->initialOptions = $options;
    }

    public function reset(): void
    {
        parent::__construct($this->root, $this->initialOptions);
    }
}
