<?php

namespace App;

use FluffyDiscord\RapiraBundle\Kernel\RapiraMicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use RapiraMicroKernelTrait;
}
