<?php

namespace Sellissimo\SzamlazzHu\Automation;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ProcessOrder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public string $orderId, public string $window) {}

    public function uniqueId(): string
    {
        return $this->orderId.':'.$this->window;
    }

    public function handle(Workflow $workflow): void
    {
        $workflow->handle($this->orderId, $this->window);
    }
}
