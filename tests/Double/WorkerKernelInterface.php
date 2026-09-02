<?php

namespace FluffyDiscord\RapiraBundle\Tests\Double;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * The kernel surface HttpWorker touches: boot/handle/terminate/reboot. Combined so a single
 * PHPUnit mock satisfies every instanceof check in the worker loop.
 */
interface WorkerKernelInterface extends KernelInterface, TerminableInterface, RebootableInterface
{
}
