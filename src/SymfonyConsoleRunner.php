<?php
declare(strict_types=1);

namespace Runtime\Swoole;

use Swoole\Event;
use Symfony\Component\Runtime\Runner\Symfony\ConsoleApplicationRunner;

use function Swoole\Coroutine\run;

final class SymfonyConsoleRunner extends ConsoleApplicationRunner
{
    #[\Override]
    public function run(): int
    {
        $status = 0;
        run(function () use (&$status) {
            $status = $this->run();
        });
        Event::wait();

        return $status;
    }
}