<?php

declare(strict_types=1);

namespace ImageOptimizer;

use ImageOptimizer\Exception\Exception;
use ImageOptimizer\Optimizer;
use ImageOptimizer\TypeGuesser\TypeGuesser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class OptimizerFactory
{
    public const OPTIMIZER_SMART = 'smart';

    /**
     * @var array<ChangedOutputOptimizer|SuppressErrorOptimizer> $optimizers
     */
    private array $optimizers = [];

    /**
     * @var array{
     *  ignore_errors: bool,
     *  execute_only_first_png_optimizer: bool,
     *  execute_only_first_jpeg_optimizer: bool,
     *  optipng_options: array<string>,
     *  pngquant_options: array<string>,
     *  pngcrush_options: array<string>,
     *  pngout_options: array<string>,
     *  gifsicle_options: array<string>,
     *  jpegoptim_options: array<string>,
     *  jpegtran_options: array<string>,
     *  advpng_options: array<string>,
     *  svgo_options: array<string>,
     *  custom_optimizers?: array<array{command: string, args: array<string>}>,
     *  single_optimizer_timeout_in_seconds: int,
     *  output_filepath_pattern: string
     * }
     */
    private array $options;
    private ExecutableFinder $executableFinder;
    private LoggerInterface $logger;

    /**
     * Undocumented function
     *
     * @param array{
     *  ignore_errors?: bool,
     *  execute_only_first_png_optimizer?: bool,
     *  execute_only_first_jpeg_optimizer?: bool,
     *  optipng_options?: array<string>,
     *  pngquant_options?: array<string>,
     *  pngcrush_options?: array<string>,
     *  pngout_options?: array<string>,
     *  gifsicle_options?: array<string>,
     *  jpegoptim_options?: array<string>,
     *  jpegtran_options?: array<string>,
     *  advpng_options?: array<string>,
     *  svgo_options?: array<string>,
     *  custom_optimizers?: array<array{command: string, args: array<string>}>,
     *  single_optimizer_timeout_in_seconds?: int,
     *  output_filepath_pattern?: string
     * } $options
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $options = [], LoggerInterface $logger = null)
    {
        $this->executableFinder = new ExecutableFinder();
        $this->logger = $logger ?: new NullLogger();

        $this->setOptions($options);
        $this->setUpOptimizers();
    }

    /**
     * @param array{
     *  ignore_errors?: bool,
     *  execute_only_first_png_optimizer?: bool,
     *  execute_only_first_jpeg_optimizer?: bool,
     *  optipng_options?: array<string>,
     *  pngquant_options?: array<string>,
     *  pngcrush_options?: array<string>,
     *  pngout_options?: array<string>,
     *  gifsicle_options?: array<string>,
     *  jpegoptim_options?: array<string>,
     *  jpegtran_options?: array<string>,
     *  advpng_options?: array<string>,
     *  svgo_options?: array<string>,
     *  custom_optimizers?: array<array{command: string, args: array<string>}>,
     *  single_optimizer_timeout_in_seconds?: int,
     *  output_filepath_pattern?: string
     * } $options
     */
    private function setOptions(array $options): void
    {
        $this->options = $this->getOptionsResolver()->resolve($options);
    }

    protected function getOptionsResolver(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'ignore_errors' => true,
            'execute_only_first_png_optimizer' => true,
            'execute_only_first_jpeg_optimizer' => true,
            'optipng_options' => ['-i0', '-o2', '-quiet'],
            'pngquant_options' => ['--force', '--skip-if-larger'],
            'pngcrush_options' => ['-reduce', '-q', '-ow'],
            'pngout_options' => ['-s3', '-q', '-y'],
            'gifsicle_options' => ['-b', '-O5'],
            'jpegoptim_options' => ['--strip-all', '--all-progressive'],
            'jpegtran_options' => ['-optimize', '-progressive'],
            'advpng_options' => ['-z', '-4', '-q'],
            'svgo_options' => ['--config=' . realpath(__DIR__ . '/../../config') . '/svgo.config.js'],
            'custom_optimizers' => [],
            'single_optimizer_timeout_in_seconds' => 60,
            'output_filepath_pattern' => '%basename%/%filename%%ext%'
        ]);

        $resolver->setDefined([
            'optipng_bin',
            'pngquant_bin',
            'pngcrush_bin',
            'pngout_bin',
            'gifsicle_bin',
            'jpegoptim_bin',
            'jpegtran_bin',
            'advpng_bin',
            'svgo_bin',
            'custom_optimizers',
            'single_optimizer_timeout_in_seconds'
        ]);

        return $resolver;
    }

    protected function setUpOptimizers(): void
    {
        $this->optimizers['optipng'] = $this->wrap(
            $this->commandOptimizer('optipng', $this->options['optipng_options'])
        );
        $this->optimizers['pngquant'] = $this->wrap(
            $this->commandOptimizer(
                'pngquant',
                $this->options['pngquant_options'],
                function ($filepath) {
                    $ext = pathinfo($filepath, PATHINFO_EXTENSION);
                    return ['--ext=' . ($ext ? '.' . $ext : ''), '--'];
                }
            )
        );
        $this->optimizers['pngcrush'] = $this->wrap(
            $this->commandOptimizer('pngcrush', $this->options['pngcrush_options'])
        );
        $this->optimizers['pngout'] = $this->wrap(
            $this->commandOptimizer('pngout', $this->options['pngout_options'])
        );
        $this->optimizers['advpng'] = $this->wrap(
            $this->commandOptimizer('advpng', $this->options['advpng_options'])
        );
        $this->optimizers['png'] = $this->wrap(new ChainOptimizer([
            $this->unwrap($this->optimizers['pngquant']),
            $this->unwrap($this->optimizers['optipng']),
            $this->unwrap($this->optimizers['pngcrush']),
            $this->unwrap($this->optimizers['advpng'])
        ], $this->options['execute_only_first_png_optimizer'], $this->logger));

        $this->optimizers['gif'] = $this->optimizers['gifsicle'] = $this->wrap(
            $this->commandOptimizer('gifsicle', $this->options['gifsicle_options'])
        );

        $this->optimizers['jpegoptim'] = $this->wrap(
            $this->commandOptimizer('jpegoptim', $this->options['jpegoptim_options'])
        );
        $this->optimizers['jpegtran'] = $this->wrap(
            $this->commandOptimizer(
                'jpegtran',
                $this->options['jpegtran_options'],
                function ($filepath) {
                    return ['-outfile', $filepath];
                }
            )
        );
        $this->optimizers['jpeg'] = $this->optimizers['jpg'] = $this->wrap(new ChainOptimizer([
            $this->unwrap($this->optimizers['jpegtran']),
            $this->unwrap($this->optimizers['jpegoptim']),
        ], $this->options['execute_only_first_jpeg_optimizer'], $this->logger));

        $this->optimizers['svg'] = $this->optimizers['svgo'] = $this->wrap(
            $this->commandOptimizer(
                'svgo',
                $this->options['svgo_options'],
                function ($filepath) {
                    return ['--output' => $filepath];
                }
            )
        );

        foreach ($this->options['custom_optimizers'] as $key => $options) {
            $this->optimizers[$key] = $this->wrap(
                $this->commandOptimizer($options['command'], $options['args'])
            );
        }

        $this->optimizers[self::OPTIMIZER_SMART] = $this->wrap(new SmartOptimizer([
            TypeGuesser::TYPE_GIF => $this->unwrap($this->optimizers['gif']),
            TypeGuesser::TYPE_PNG => $this->unwrap($this->optimizers['png']),
            TypeGuesser::TYPE_JPEG => $this->unwrap($this->optimizers['jpeg']),
            TypeGuesser::TYPE_SVG => $this->unwrap($this->optimizers['svg'])
        ]));
    }

    /**
     * Returns a list of optimizer executeable paths and whether or not
     * they're installed.
     *
     * @return array<bool>
     */
    public function checkOptimizers(): array
    {
        $apps = [];

        // Get a complete list of apps we currently support
        foreach ($this->optimizers as $optimizer) {
            $unwrapped = $optimizer->unwrap();

            if ($unwrapped instanceof ChainOptimizer) {
                foreach ($unwrapped->optimizers as $commandOptimizer) {
                    $apps[$commandOptimizer->command->cmd] = false;
                }
            } elseif ($unwrapped instanceof CommandOptimizer) {
                $apps[$unwrapped->command->cmd] = false;
            }
        }

        // Loop through our apps calling 'which' on them to see if they're installed
        foreach (array_keys($apps) as $app) {
            $process = new Process(['which', $app]);
            $process->run();

            $apps[$app] = $process->isSuccessful();
        }

        return $apps;
    }

    /**
     * @param string $command
     * @param array<string> $args
     * @param ?\Closure $extraArgs
     * @return CommandOptimizer
     */
    private function commandOptimizer(string $command, array $args, ?\Closure $extraArgs = null): CommandOptimizer
    {
        return new CommandOptimizer(
            new Command($this->executable($command), $args, $this->options['single_optimizer_timeout_in_seconds']),
            $extraArgs
        );
    }

    private function wrap(
        CommandOptimizer|ChainOptimizer|SmartOptimizer $optimizer
    ): ChangedOutputOptimizer|SuppressErrorOptimizer {
        $optimizer = new ChangedOutputOptimizer(
            $this->option('output_filepath_pattern'),
            $optimizer
        );

        return $this->option('ignore_errors', true) ?
            new SuppressErrorOptimizer($optimizer, $this->logger) :
            $optimizer;
    }

    private function unwrap(
        ChangedOutputOptimizer|SuppressErrorOptimizer $optimizer
    ): CommandOptimizer|ChainOptimizer|SmartOptimizer {
        return $optimizer->unwrap();
    }

    private function executable(string $name): string
    {
        $executableFinder = $this->executableFinder;
        return $this->option($name . '_bin', function () use ($name, $executableFinder) {
            return $executableFinder->find($name, $name);
        });
    }

    private function option(string $name, mixed $default = null): mixed
    {
        return isset($this->options[$name]) ? $this->options[$name] : $this->resolveDefault($default);
    }

    /**
     * @param string $name
     * @return Optimizer
     * @throws Exception When requested optimizer does not exist
     */
    public function get(string $name = self::OPTIMIZER_SMART): Optimizer
    {
        if (!isset($this->optimizers[$name])) {
            throw new Exception(sprintf('Optimizer "%s" not found', $name));
        }

        return $this->optimizers[$name];
    }

    private function resolveDefault(mixed $default): mixed
    {
        return is_callable($default) ? call_user_func($default) : $default;
    }
}
