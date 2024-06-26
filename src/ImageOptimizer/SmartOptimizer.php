<?php

declare(strict_types=1);

namespace ImageOptimizer;

use ImageOptimizer\Exception\Exception;
use ImageOptimizer\TypeGuesser\SmartTypeGuesser;
use ImageOptimizer\TypeGuesser\TypeGuesser;

/**
 * Picks optimizer based on file type
 */
class SmartOptimizer implements Optimizer
{
    /**
     * @var array<Optimizer> $optimizers
     */
    private array $optimizers;
    private TypeGuesser $typeGuesser;

    /**
     * @param array<Optimizer> $optimizers
     */
    public function __construct(array $optimizers, TypeGuesser $typeGuesser = null)
    {
        $this->optimizers = $optimizers;
        $this->typeGuesser = $typeGuesser ?: new SmartTypeGuesser();
    }

    public function optimize(string $filepath): void
    {
        $type = $this->typeGuesser->guess($filepath);

        if (!isset($this->optimizers[$type])) {
            throw new Exception(sprintf('Optimizer for type "%s" not found.', $type));
        }

        $this->optimizers[$type]->optimize($filepath);
    }
}
