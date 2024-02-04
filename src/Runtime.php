<?php

namespace Runtime\Swoole;

use Illuminate\Contracts\Http\Kernel;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * A runtime for Swoole.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class Runtime extends SymfonyRuntime
{
    /** @var ?ServerFactory */
    private $serverFactory;

    private readonly ConsoleOutput $output;
    private readonly ArgvInput $input;
    private readonly Application $console;
    private readonly Command $command;

    public function __construct(array $options, ?ServerFactory $serverFactory = null)
    {
        $this->serverFactory = $serverFactory ?? new ServerFactory($options);
        parent::__construct($this->serverFactory->getOptions());
    }

    public function getRunner(?object $application): RunnerInterface
    {
        if (is_callable($application)) {
            return new CallableRunner($this->serverFactory, $application);
        }

        if ($application instanceof HttpKernelInterface) {
            return new SymfonyRunner($this->serverFactory, $application);
        }

        if ($application instanceof Kernel) {
            return new LaravelRunner($this->serverFactory, $application);
        }

        if ($application instanceof Command) {
            $console = $this->console ??= new Application();
            $console->setName($application->getName() ?: $console->getName());

            if (!$application->getName() || !$console->has($application->getName())) {
                $application->setName($_SERVER['argv'][0]);
                $console->add($application);
            }

            $console->setDefaultCommand($application->getName(), true);
            $console->getDefinition()->addOptions($application->getDefinition()->getOptions());

            return $this->getRunner($console);
        }

        if ($application instanceof Application) {
            if (!\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
                echo 'Warning: The console should be invoked via the CLI version of PHP, not the '.\PHP_SAPI.' SAPI'.\PHP_EOL;
            }

            set_time_limit(0);
            $defaultEnv = !isset($this->options['env']) ? ($_SERVER[$this->options['env_var_name']] ?? 'dev') : null;
            $output = $this->output ??= new ConsoleOutput();
            return new SymfonyConsoleRunner($application, $defaultEnv, $this->getInput(), $output);
        }

        if (isset($this->command)) {
            $this->getInput()->bind($this->command->getDefinition());
        }

        return parent::getRunner($application);
    }

    protected function getArgument(\ReflectionParameter $parameter, ?string $type): mixed
    {
        return match ($type) {
            Request::class => Request::createFromGlobals(),
            InputInterface::class => $this->getInput(),
            OutputInterface::class => $this->output ??= new ConsoleOutput(),
            Application::class => $this->console ??= new Application(),
            Command::class => $this->command ??= new Command(),
            default => parent::getArgument($parameter, $type),
        };
    }

    private function getInput(): ArgvInput
    {
        if (isset($this->input)) {
            return $this->input;
        }

        $input = new ArgvInput();

        if (isset($this->options['env'])) {
            return $this->input = $input;
        }

        if (null !== $env = $input->getParameterOption(['--env', '-e'], null, true)) {
            putenv($this->options['env_var_name'].'='.$_SERVER[$this->options['env_var_name']] = $_ENV[$this->options['env_var_name']] = $env);
        }

        if ($input->hasParameterOption('--no-debug', true)) {
            putenv($this->options['debug_var_name'].'='.$_SERVER[$this->options['debug_var_name']] = $_ENV[$this->options['debug_var_name']] = '0');
        }

        return $this->input = $input;
    }
}
