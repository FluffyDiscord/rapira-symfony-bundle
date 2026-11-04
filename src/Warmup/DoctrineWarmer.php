<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;

readonly class DoctrineWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private ?ManagerRegistry $managerRegistry = null,
    )
    {
    }

    public function warmup(): void
    {
        if ($this->managerRegistry === null) {
            return;
        }

        foreach ($this->managerRegistry->getManagers() as $manager) {
            $allMetadata = $manager->getMetadataFactory()->getAllMetadata();

            if (!$manager instanceof EntityManagerInterface) {
                continue;
            }

            $unitOfWork = $manager->getUnitOfWork();

            foreach ($allMetadata as $metadata) {
                if (!$metadata instanceof ClassMetadata || $metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                $unitOfWork->getEntityPersister($metadata->getName());
            }
        }
    }
}
