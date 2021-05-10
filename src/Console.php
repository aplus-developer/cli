<?php namespace Framework\CLI;

use Framework\CLI\Commands\Help;
use Framework\CLI\Commands\Index;
use Framework\Language\Language;

/**
 * Class Console.
 */
class Console
{
	/**
	 * List of commands.
	 *
	 * @var array|Command[]
	 */
	protected array $commands = [];
	/**
	 * The current command name.
	 */
	protected string $command = '';
	/**
	 * Input options.
	 *
	 * @var array|mixed[]
	 */
	protected array $options = [];
	/**
	 * Input arguments.
	 *
	 * @var array|string[]
	 */
	protected array $arguments = [];
	/**
	 * The Language instance.
	 */
	protected Language $language;

	/**
	 * Console constructor.
	 *
	 * @param Language|null $language
	 */
	public function __construct(Language $language = null)
	{
		if ($language === null) {
			$language = new Language('en');
		}
		$this->language = $language->addDirectory(__DIR__ . '/Languages');
		global $argv;
		$this->prepare($argv);
	}

	/**
	 * Get all CLI options.
	 *
	 * @return array|string[]
	 */
	public function getOptions() : array
	{
		return $this->options;
	}

	/**
	 * Get a specific option or null.
	 *
	 * @param string $option
	 *
	 * @return string|null
	 */
	public function getOption(string $option) : ?string
	{
		return $this->options[$option] ?? null;
	}

	/**
	 * Get all arguments.
	 *
	 * @return array|string[]
	 */
	public function getArguments() : array
	{
		return $this->arguments;
	}

	/**
	 * Get a specific argument or null.
	 *
	 * @param string $argument
	 *
	 * @return string|null
	 */
	public function getArgument(string $argument) : ?string
	{
		return $this->arguments[$argument] ?? null;
	}

	/**
	 * Get the Language instance.
	 *
	 * @return Language
	 */
	public function getLanguage() : Language
	{
		return $this->language;
	}

	/**
	 * Add a command to the console.
	 *
	 * @param Command|string $command
	 *
	 * @return $this
	 */
	public function addCommand(Command | string $command)
	{
		if (\is_string($command)) {
			$command = new $command($this);
		}
		$this->commands[$command->getName()] = $command;
		return $this;
	}

	/**
	 * Add many commands to the console.
	 *
	 * @param array|Command[]|string[] $commands
	 *
	 * @return $this
	 */
	public function addCommands(array $commands)
	{
		foreach ($commands as $command) {
			$this->addCommand($command);
		}
		return $this;
	}

	/**
	 * Get a command.
	 *
	 * @param string $name Command name
	 *
	 * @return Command|null The command on success or null if not found
	 */
	public function getCommand(string $name) : ?Command
	{
		if (isset($this->commands[$name]) && $this->commands[$name]->isActive()) {
			return $this->commands[$name];
		}
		return null;
	}

	/**
	 * Get a list of active commands.
	 *
	 * @return array|Command[]
	 */
	public function getCommands() : array
	{
		$commands = $this->commands;
		foreach ($commands as $name => $command) {
			if ( ! $command->isActive()) {
				unset($commands[$name]);
			}
		}
		\ksort($commands);
		return $commands;
	}

	/**
	 * Run the Console.
	 */
	public function run() : void
	{
		if ($this->getCommand('index') === null) {
			$this->addCommand(new Index($this));
		}
		if ($this->getCommand('help') === null) {
			$this->addCommand(new Help($this));
		}
		if ($this->command === '') {
			$this->command = 'index';
		}
		$command = $this->getCommand($this->command);
		if ($command === null) {
			CLI::error(CLI::style(
				$this->getLanguage()->render('cli', 'commandNotFound', [$this->command]),
				CLI::FG_BRIGHT_RED
			));
		}
		$command->run();
	}

	public function exec(string $command) : void
	{
		$argument_values = static::commandToArgs($command);
		\array_unshift($argument_values, 'removed');
		$this->prepare($argument_values);
		$this->run();
	}

	protected function reset() : void
	{
		$this->command = '';
		$this->options = [];
		$this->arguments = [];
	}

	/**
	 * Prepare information of the command line.
	 *
	 * [options] [arguments] [options]
	 * [options] -- [arguments]
	 * [command]
	 * [command] [options] [arguments] [options]
	 * [command] [options] -- [arguments]
	 * Short option: -l, -la === l = true, a = true
	 * Long option: --list, --all=vertical === list = true, all = vertical
	 * Only Long Options receive values:
	 * --foo=bar or --f=bar - "foo" and "f" are bar
	 * -foo=bar or -f=bar - all characters are true (f, o, =, b, a, r)
	 * After -- all values are arguments, also if is prefixed with -
	 * Without --, arguments and options can be mixed: -ls foo -x abc --a=e.
	 */
	protected function prepare(array $argument_values) : void
	{
		$this->reset();
		unset($argument_values[0]);
		if (isset($argument_values[1]) && $argument_values[1][0] !== '-') {
			$this->command = $argument_values[1];
			unset($argument_values[1]);
		}
		$end_options = false;
		foreach ($argument_values as $value) {
			if ($end_options === false && $value === '--') {
				$end_options = true;
				continue;
			}
			if ($end_options === false && $value[0] === '-') {
				if (isset($value[1]) && $value[1] === '-') {
					$option = \substr($value, 2);
					if (\str_contains($option, '=')) {
						[$option, $value] = \explode('=', $option, 2);
						$this->options[$option] = $value;
						continue;
					}
					$this->options[$option] = true;
					continue;
				}
				foreach (\str_split(\substr($value, 1)) as $item) {
					$this->options[$item] = true;
				}
				continue;
			}
			//$end_options = true;
			$this->arguments[] = $value;
		}
	}

	/**
	 * @param string $command
	 *
	 * @see https://someguyjeremy.com/2017/07/adventures-in-parsing-strings-to-argv-in-php.html
	 *
	 * @return array
	 */
	public static function commandToArgs(string $command) : array
	{
		$charCount = \strlen($command);
		$argv = [];
		$arg = '';
		$inDQuote = false;
		$inSQuote = false;
		for ($i = 0; $i < $charCount; $i++) {
			$char = $command[$i];
			if ($char === ' ' && ! $inDQuote && ! $inSQuote) {
				if ($arg !== '') {
					$argv[] = $arg;
				}
				$arg = '';
				continue;
			}
			if ($inSQuote && $char === "'") {
				$inSQuote = false;
				continue;
			}
			if ($inDQuote && $char === '"') {
				$inDQuote = false;
				continue;
			}
			if ($char === '"' && ! $inSQuote) {
				$inDQuote = true;
				continue;
			}
			if ($char === "'" && ! $inDQuote) {
				$inSQuote = true;
				continue;
			}
			$arg .= $char;
		}
		$argv[] = $arg;
		return $argv;
	}
}
