<?php

namespace App\Core\Components;

use Phalcon\Config\ConfigInterface;
use Phalcon\Config\Config as ConfigConfig;

/**
 * Class Config
 *
 * Extends Phalcon's Config class to provide a custom implementation of configuration merging
 * that is compatible with Phalcon 4's way of handling configurations.
 *
 * @package App\Core\Components
 */
class Config extends ConfigConfig
{
    /**
     * Merges the provided configuration with the current configuration.
     *
     * This method supports merging with both arrays and other instances of `ConfigInterface`.
     * It converts the current configuration to an array, merges it with the provided configuration,
     * and then initializes the object with the merged result.
     *
     * @param mixed $toMerge The configuration to merge with. Can be an array or an instance of `ConfigInterface`.
     * @return ConfigInterface The current instance with the updated configuration.
     * @throws \Exception If the provided configuration is neither an array nor an instance of `ConfigInterface`.
     */
    public function merge($toMerge): ConfigInterface
    {
        $result = $source = [];

        // Convert current object to array
        $source = $this->toArray();

        // Clear the current object state
        $this->clear();

        if (is_array($toMerge)) {
            // Merge with array
            $result = $this->extendedInternalMerge($source, $toMerge);
            $this->init($result);
            return $this;
        }

        if (is_object($toMerge) && $toMerge instanceof ConfigInterface) {
            // Merge with another ConfigInterface instance
            $result = $this->extendedInternalMerge($source, $toMerge->toArray());
            $this->init($result);
            return $this;
        }

        throw new \Exception("Invalid data type for merge.");
    }

    /**
     * Recursively merges two arrays.
     *
     * This method is used internally to merge the source configuration with the target configuration.
     * It handles merging nested arrays and numeric keys.
     *
     * @param array $source The source configuration array.
     * @param array $target The target configuration array to merge into the source.
     * @return array The merged configuration array.
     */
    protected function extendedInternalMerge(array $source, array $target): array
    {
        foreach ($target as $key => $value) {
            if (is_array($value) && isset($source[$key]) && is_array($source[$key])) {
                $source[$key] = $this->extendedInternalMerge($source[$key], $value);
            } elseif (is_int($key)) {
                $source[] = $value;
            } else {
                $source[$key] = $value;
            }
        }

        return $source;
    }
}
