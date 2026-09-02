<?php

namespace FluffyDiscord\RapiraBundle;

use FluffyDiscord\RapiraBundle\DependencyInjection\FluffyDiscordRapiraExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class FluffyDiscordRapiraBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        // The extension's alias is "rapira", not the "fluffy_discord_rapira" the bundle name
        // implies, so return it explicitly to opt out of the convention check.
        if ($this->extension === null) {
            $this->extension = new FluffyDiscordRapiraExtension();
        }

        return $this->extension ?: null;
    }
}
