<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\FormTypeInterface;

readonly class FormRegistryWarmer implements WorkerWarmerInterface
{
    /**
     * @param iterable<FormTypeInterface> $formTypes services tagged form.type
     */
    public function __construct(
        private iterable               $formTypes = [],
        private ?FormRegistryInterface $formRegistry = null,
    )
    {
    }

    public function warmup(): void
    {
        if ($this->formRegistry === null) {
            return;
        }

        foreach ($this->formTypes as $formType) {
            try {
                $this->formRegistry->getType($formType::class);
            } catch (\Throwable) {
            }
        }
    }
}
