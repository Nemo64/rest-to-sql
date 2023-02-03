<?php

namespace Nemo64\RestToSql;

/**
 * This is a wrapper for arbitrary options.
 * It tracks what options were used and can throw an exception if there are unused options.
 */
class Options implements \ArrayAccess, \IteratorAggregate
{
    private array $usedOptions = [];

    /** @var Options[] */
    private array $subOptions = [];

    public function __construct(
        private array $options,
    ) {

    }

    public function getUnusedOptions(): array
    {
        $options = array_diff_key($this->options, $this->usedOptions);
        foreach ($this->subOptions as $key => $subOption) {
            foreach ($subOption->getUnusedOptions() as $unusedOption) {
                $options["$key.$unusedOption"] = true;
            }
        }
        return array_keys($options);
    }

    public function throwForUnusedOptions(): void
    {
        $unusedOptions = $this->getUnusedOptions();
        if (count($unusedOptions) > 0) {
            throw new \LogicException("The following options are not used:\n - " . implode("\n - ", $unusedOptions));
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->usedOptions[$offset] = true;
        return array_key_exists($offset, $this->options);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->usedOptions[$offset] = true;
        $value = $this->options[$offset];
        if (!is_array($value)) {
            return $value;
        }

        if (!isset($this->subOptions[$offset])) {
            $this->subOptions[$offset] = new Options($value);
        }

        return $this->subOptions[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->options[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->options[$offset]);
    }

    public function getIterator(): \Iterator
    {
        foreach ($this->options as $key => $value) {
            yield $key => $this[$key];
        }
    }
}