<?php

namespace App\Core\Assets;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * The JSON Schema of each asset kind (packages/schemas/<kind>.schema.json).
 */
class AssetSchemas
{
    /** @var array<string, object> loaded schemas by kind */
    private array $schemas = [];

    public function __construct(private string $path) {}

    /**
     * @throws ValidationException listing what's wrong with the spec
     */
    public function validate(string $kind, mixed $spec): void
    {
        // Collect up to 20 errors instead of stopping at the first one.
        $result = (new Validator(null, 20, false))->validate($spec, $this->schema($kind));

        if ($result->isValid()) {
            return;
        }

        // { "/scenes/0/title": ["The data (null) must match the type: string"], ... } as "spec.scenes.0.title" errors.
        $errors = [];
        foreach ((new ErrorFormatter)->format($result->error()) as $pointer => $messages) {
            $errors['spec'.($pointer === '/' ? '' : '.'.str_replace('/', '.', ltrim($pointer, '/')))] = $messages;
        }

        throw ValidationException::withMessages($this->withoutEchoes($errors));
    }

    /**
     * When something inside an object fails, the validator also says the object's own properties
     * are "not allowed" (with additionalProperties: false). Drop those echoes where a deeper error
     * explains the problem.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function withoutEchoes(array $errors): array
    {
        foreach ($errors as $key => $messages) {
            $hasDeeper = collect(array_keys($errors))->contains(fn (string $other) => str_starts_with($other, "{$key}."));
            if ($hasDeeper) {
                $errors[$key] = array_values(array_filter($messages, fn (string $message) => ! str_starts_with($message, 'Additional object properties are not allowed')));
            }
        }

        return array_filter($errors);
    }

    /**
     * x-schema-version of the kind's schema.
     */
    public function version(string $kind): int
    {
        return (int) ($this->schema($kind)->{'x-schema-version'} ?? 1);
    }

    private function schema(string $kind): object
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $kind)) {
            throw new RuntimeException("Invalid asset kind [{$kind}].");
        }

        return $this->schemas[$kind] ??= json_decode(
            file_get_contents("{$this->path}/{$kind}.schema.json") ?: throw new RuntimeException("No schema for asset kind [{$kind}]."),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
