<?php

namespace App\Installer\EnvFiles;

use App\Utilities\Strings;
use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use InvalidArgumentException;

abstract class AbstractEnvFile implements \ArrayAccess
{
    final public function __construct(
        protected string $path,
        protected array $data = []
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getBasename(): string
    {
        return basename($this->path);
    }

    public function setFromDefaults(): void
    {
        $currentVars = array_filter($this->data);

        $defaults = [];
        foreach (static::getConfiguration() as $key => $keyInfo) {
            if (isset($keyInfo['default'])) {
                $defaults[$key] = $keyInfo['default'] ?? null;
            }
        }

        $this->data = array_merge($defaults, $currentVars);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->data[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    public function writeToFile(): string
    {
        $values = array_filter($this->data);

        $envFile = [
            '# ' . __('This file was automatically generated by AzuraCast.'),
            '# ' . __('You can modify it as necessary. To apply changes, restart the Docker containers.'),
            '# ' . __('Remove the leading "#" symbol from lines to uncomment them.'),
            '',
        ];

        foreach (static::getConfiguration() as $key => $keyInfo) {
            $envFile[] = '# ' . ($keyInfo['name'] ?? $key);

            if (!empty($keyInfo['description'])) {
                $desc = Strings::mbWordwrap($keyInfo['description']);

                foreach (explode("\n", $desc) as $descPart) {
                    $envFile[] = '# ' . $descPart;
                }
            }

            if (!empty($keyInfo['options'])) {
                $options = array_map(
                    fn($val) => $this->getEnvValue($val),
                    $keyInfo['options'],
                );

                $envFile[] = '# ' . __('Valid options: %s', implode(', ', $options));
            }

            if (isset($values[$key])) {
                $value = $this->getEnvValue($values[$key]);
                unset($values[$key]);
            } else {
                $value = null;
            }

            if (!empty($keyInfo['default'])) {
                $default = $this->getEnvValue($keyInfo['default']);
                $envFile[] = '# ' . __('Default: %s', $default);
            } else {
                $default = '';
            }

            $isRequired = (bool)($keyInfo['required'] ?? false);

            if (null === $value || ($default === $value && !$isRequired)) {
                $value ??= $default;
                $envFile[] = '# ' . $key . '=' . $value;
            } else {
                $envFile[] = $key . '=' . $value;
            }

            $envFile[] = '';
        }

        // Add in other environment vars that were missed or previously present.
        if (!empty($values)) {
            $envFile[] = '# ' . __('Additional Environment Variables');

            foreach ($values as $key => $value) {
                $envFile[] = $key . '=' . $this->getEnvValue($value);
            }
        }

        $envFileStr = implode("\n", $envFile);
        file_put_contents($this->path, $envFileStr);

        return $envFileStr;
    }

    protected function getEnvValue(
        mixed $value
    ): string {
        if (is_null($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_array($value)) {
            return implode(',', $value);
        }

        if (str_contains($value, ' ')) {
            $value = '"' . $value . '"';
        }

        return $value;
    }

    /**
     * @return mixed[]
     */
    abstract public static function getConfiguration(): array;

    abstract public static function buildPathFromBase(string $baseDir): string;

    public static function fromEnvFile(string $path): static
    {
        $data = [];
        if (is_file($path)) {
            $fileContents = file_get_contents($path);
            if (!empty($fileContents)) {
                try {
                    $data = Dotenv::parse($fileContents);
                } catch (ExceptionInterface $e) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Encountered an error parsing %s: "%s". Resetting to default configuration.',
                            basename($path),
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        return new static($path, $data);
    }
}
