<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use Throwable;

class GenerateOpenApiSpec extends Command
{
    protected $signature = 'openapi:generate
                            {--output=public/openapi.json : Ruta del archivo a generar}';

    protected $description = 'Genera la especificación OpenAPI 3.0 a partir de las rutas y los FormRequest del proyecto';

    /**
     * Rutas que no forman parte de la API documentada.
     */
    private const EXCLUDED_URIS = [
        'api/v1/user',
    ];

    /** @var array<string, true> */
    private array $usedOperationIds = [];

    private int $rulesFromFormRequest = 0;

    private int $rulesFromInlineValidate = 0;

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $paths = [];
        $routes = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route) => Str::startsWith($route->uri(), 'api/'))
            ->reject(fn (RoutingRoute $route) => in_array($route->uri(), self::EXCLUDED_URIS, true));

        foreach ($routes as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $path = '/'.$route->uri();
                $paths[$path][strtolower($method)] = $this->buildOperation($route, $method);
            }
        }

        ksort($paths);

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name'),
                'version' => '1.0.0',
                'description' => 'Documentación generada automáticamente desde `routes/api.php` y las clases '
                    ."`App\\Http\\Requests\\*`.\n\nPara probar los endpoints protegidos: llama a "
                    .'`POST /api/auth/login`, copia el `access_token` y pégalo en **Authorize**.',
            ],
            'servers' => [
                // URL relativa: "Try it out" usa siempre el origen desde el que se
                // sirve la documentación, sin depender de que APP_URL coincida.
                ['url' => '/', 'description' => 'Servidor actual'],
                ['url' => rtrim(config('app.url'), '/'), 'description' => 'APP_URL ('.config('app.env').')'],
            ],
            'components' => [
                'securitySchemes' => [
                    'sanctum' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Token personal de Laravel Sanctum.',
                    ],
                ],
            ],
            'tags' => $this->buildTags($paths),
            'paths' => $paths,
        ];

        $output = $this->option('output');
        $target = Str::startsWith($output, '/') ? $output : base_path($output);

        file_put_contents(
            $target,
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $operations = collect($paths)->sum(fn (array $methods) => count($methods));

        $this->info("Especificación generada en {$target}");
        $this->line("  Endpoints documentados: {$operations}");
        $this->line("  Esquemas desde FormRequest: {$this->rulesFromFormRequest}");
        $this->line("  Esquemas desde \$request->validate(): {$this->rulesFromInlineValidate}");

        foreach ($this->warnings as $warning) {
            $this->warn("  {$warning}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOperation(RoutingRoute $route, string $method): array
    {
        $reflection = $this->reflectAction($route);
        $rules = $this->rulesFor($route, $reflection);
        $expectsBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);

        $operation = [
            'tags' => [$this->tagFor($route)],
            'summary' => $this->summaryFor($route, $reflection),
            'operationId' => $this->operationIdFor($route, $method),
            'parameters' => array_merge(
                $this->pathParameters($route),
                $expectsBody ? [] : $this->queryParameters($rules)
            ),
            'responses' => $this->responsesFor($route, $expectsBody),
        ];

        if ($expectsBody && $rules !== []) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => $this->schemaFromRules($rules)],
                ],
            ];
        }

        if ($this->isProtected($route)) {
            $operation['security'] = [['sanctum' => []]];
        }

        if ($operation['parameters'] === []) {
            unset($operation['parameters']);
        }

        return $operation;
    }

    private function reflectAction(RoutingRoute $route): ?ReflectionMethod
    {
        $action = $route->getActionName();

        if (! Str::contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action);

        try {
            return new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Obtiene las reglas de validación: primero del FormRequest inyectado y,
     * si el método recibe una Request genérica, del `$request->validate([...])`
     * escrito dentro del cuerpo del método.
     *
     * @return array<string, string>
     */
    private function rulesFor(RoutingRoute $route, ?ReflectionMethod $reflection): array
    {
        if ($reflection === null) {
            return [];
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (! is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            try {
                $rules = (new $class)->rules();
                $this->rulesFromFormRequest++;

                return $this->normalizeRules($rules);
            } catch (Throwable $e) {
                $this->warnings[] = sprintf(
                    '%s: no se pudieron leer las reglas de %s (%s)',
                    $route->uri(),
                    class_basename($class),
                    $e->getMessage()
                );

                return [];
            }
        }

        return $this->inlineValidationRules($reflection);
    }

    /**
     * Extrae las reglas de un `$request->validate([...])` literal escrito en el
     * cuerpo del método. Solo reconoce pares 'campo' => 'reglas' con strings
     * literales, que es la forma usada en este proyecto.
     *
     * @return array<string, string>
     */
    private function inlineValidationRules(ReflectionMethod $reflection): array
    {
        $file = $reflection->getFileName();

        if ($file === false) {
            return [];
        }

        $lines = array_slice(
            file($file),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        );

        $source = implode('', $lines);

        if (! preg_match('/->validate\(\s*\[(.*?)\]\s*\)/s', $source, $match)) {
            return [];
        }

        preg_match_all(
            "/'([^']+)'\s*=>\s*'([^']*)'/",
            $match[1],
            $pairs,
            PREG_SET_ORDER
        );

        if ($pairs === []) {
            return [];
        }

        $rules = [];

        foreach ($pairs as $pair) {
            $rules[$pair[1]] = $pair[2];
        }

        $this->rulesFromInlineValidate++;

        return $rules;
    }

    /**
     * Descarta reglas expresadas como objetos (Rule::in, Password::min, ...) que
     * no se pueden inspeccionar de forma fiable como string.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, string>
     */
    private function normalizeRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $field => $rule) {
            if (is_string($rule)) {
                $normalized[$field] = $rule;

                continue;
            }

            if (is_array($rule)) {
                $strings = array_filter($rule, 'is_string');
                $normalized[$field] = implode('|', $strings);
            }
        }

        return $normalized;
    }

    /**
     * Convierte reglas en notación de puntos ('client.email', 'detalles.*.codigo')
     * en un esquema JSON anidado.
     *
     * @param  array<string, string>  $rules
     * @return array<string, mixed>
     */
    private function schemaFromRules(array $rules): array
    {
        $schema = ['type' => 'object', 'properties' => []];

        foreach ($rules as $field => $rule) {
            $this->insertIntoSchema($schema, explode('.', $field), $rule);
        }

        return $this->pruneSchema($schema);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $segments
     */
    private function insertIntoSchema(array &$schema, array $segments, string $rule): void
    {
        $segment = array_shift($segments);

        if ($segment === '*') {
            $schema['type'] = 'array';
            unset($schema['properties']);
            $schema['items'] ??= ['type' => 'object', 'properties' => []];

            if ($segments === []) {
                $schema['items'] = array_merge($schema['items'], $this->schemaForRule($rule));

                return;
            }

            $this->insertIntoSchema($schema['items'], $segments, $rule);

            return;
        }

        $schema['properties'] ??= [];
        $schema['properties'][$segment] ??= ['type' => 'object', 'properties' => []];

        if ($segments === []) {
            // Conserva las propiedades hijas ya registradas por reglas anteriores.
            $existing = $schema['properties'][$segment];
            $leaf = $this->schemaForRule($rule);

            if (isset($existing['properties']) && $existing['properties'] !== [] && $leaf['type'] === 'object') {
                $leaf = array_merge($leaf, ['properties' => $existing['properties']]);
            }

            if (isset($existing['items']) && ($leaf['type'] ?? null) === 'array') {
                $leaf['items'] = $existing['items'];
            }

            $schema['properties'][$segment] = $leaf;

            if ($this->isRequired($rule)) {
                $schema['required'] = array_values(array_unique(
                    array_merge($schema['required'] ?? [], [$segment])
                ));
            }

            return;
        }

        $this->insertIntoSchema($schema['properties'][$segment], $segments, $rule);

        if ($this->isRequired($rule)) {
            $schema['required'] = array_values(array_unique(
                array_merge($schema['required'] ?? [], [$segment])
            ));
        }
    }

    /**
     * Traduce una cadena de reglas de Laravel a un esquema OpenAPI.
     *
     * @return array<string, mixed>
     */
    private function schemaForRule(string $rule): array
    {
        $tokens = array_filter(explode('|', $rule));
        $schema = ['type' => 'string'];
        $notes = [];

        foreach ($tokens as $token) {
            [$name, $argument] = array_pad(explode(':', $token, 2), 2, null);

            match ($name) {
                'integer' => $schema['type'] = 'integer',
                'numeric', 'decimal' => $schema['type'] = 'number',
                'boolean' => $schema['type'] = 'boolean',
                'array' => $schema['type'] = 'array',
                'date' => $schema = ['type' => 'string', 'format' => 'date'] + $schema,
                'email' => $schema = ['type' => 'string', 'format' => 'email'] + $schema,
                'url' => $schema = ['type' => 'string', 'format' => 'uri'] + $schema,
                'file', 'image' => $schema = ['type' => 'string', 'format' => 'binary'] + $schema,
                'nullable' => $schema['nullable'] = true,
                default => null,
            };

            if ($name === 'in' && $argument !== null) {
                $schema['enum'] = explode(',', $argument);
            }

            if ($name === 'exists' && $argument !== null) {
                $notes[] = 'Debe existir en `'.$argument.'`.';
            }

            if ($name === 'unique' && $argument !== null) {
                $notes[] = 'Debe ser único en `'.$argument.'`.';
            }

            if ($name === 'size' && is_numeric($argument)) {
                $schema['minLength'] = (int) $argument;
                $schema['maxLength'] = (int) $argument;
            }

            if ($name === 'after_or_equal' || $name === 'after') {
                $notes[] = 'Debe ser posterior a `'.$argument.'`.';
            }

            if (Str::startsWith($name, 'required_')) {
                $notes[] = 'Condicional: `'.$token.'`.';
            }
        }

        // max/min se interpretan según el tipo ya resuelto.
        foreach ($tokens as $token) {
            [$name, $argument] = array_pad(explode(':', $token, 2), 2, null);

            if (! in_array($name, ['max', 'min'], true) || ! is_numeric($argument)) {
                continue;
            }

            $numericType = in_array($schema['type'], ['integer', 'number'], true);
            $key = match (true) {
                $numericType && $name === 'max' => 'maximum',
                $numericType && $name === 'min' => 'minimum',
                $schema['type'] === 'array' && $name === 'max' => 'maxItems',
                $schema['type'] === 'array' && $name === 'min' => 'minItems',
                $name === 'max' => 'maxLength',
                default => 'minLength',
            };

            $schema[$key] = $argument + 0;
        }

        if ($schema['type'] === 'array') {
            $schema['items'] ??= ['type' => 'object', 'properties' => []];
        }

        if ($notes !== []) {
            $schema['description'] = implode(' ', $notes);
        }

        return $schema;
    }

    private function isRequired(string $rule): bool
    {
        return in_array('required', explode('|', $rule), true);
    }

    /**
     * Elimina los contenedores 'object' que quedaron sin propiedades.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function pruneSchema(array $schema): array
    {
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                $schema['properties'][$key] = $this->pruneSchema($property);
            }

            if ($schema['properties'] === []) {
                unset($schema['properties']);
            }
        }

        if (isset($schema['items'])) {
            $schema['items'] = $this->pruneSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * @param  array<string, string>  $rules
     * @return list<array<string, mixed>>
     */
    private function queryParameters(array $rules): array
    {
        $parameters = [];

        foreach ($rules as $field => $rule) {
            if (Str::contains($field, '*')) {
                continue;
            }

            $parameters[] = [
                'name' => $field,
                'in' => 'query',
                'required' => $this->isRequired($rule),
                'schema' => $this->schemaForRule($rule),
            ];
        }

        return $parameters;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pathParameters(RoutingRoute $route): array
    {
        preg_match_all('/\{(\w+)(\?)?\}/', $route->uri(), $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [
            'name' => $match[1],
            'in' => 'path',
            'required' => ! isset($match[2]),
            'schema' => ['type' => $this->isStringPathParameter($route, $match[1]) ? 'string' : 'integer'],
        ], $matches);
    }

    /**
     * Los identificadores de modelo son enteros; los tokens de invitación y
     * los códigos de ubigeo (6 dígitos con ceros a la izquierda) son cadenas.
     */
    private function isStringPathParameter(RoutingRoute $route, string $name): bool
    {
        return $name === 'token' || ($name === 'id' && str_contains($route->uri(), 'ubigeos/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function responsesFor(RoutingRoute $route, bool $expectsBody): array
    {
        $responses = [
            '200' => ['description' => 'Operación exitosa'],
        ];

        if ($expectsBody) {
            $responses['422'] = ['description' => 'Error de validación'];
        }

        if ($this->isProtected($route)) {
            $responses['401'] = ['description' => 'No autenticado'];
        }

        $responses['500'] = ['description' => 'Error interno'];

        return $responses;
    }

    private function isProtected(RoutingRoute $route): bool
    {
        return collect($route->gatherMiddleware())
            ->contains(fn ($middleware) => is_string($middleware) && Str::startsWith($middleware, 'auth:'));
    }

    private function tagFor(RoutingRoute $route): string
    {
        $segments = explode('/', $route->uri());
        array_shift($segments); // 'api'

        if (($segments[0] ?? null) === 'v1') {
            array_shift($segments);
        }

        return $segments[0] ?? 'general';
    }

    private function operationIdFor(RoutingRoute $route, string $method): string
    {
        $action = $route->getActionName();

        if (Str::contains($action, '@')) {
            [$class, $controllerMethod] = explode('@', $action);
            $id = Str::camel(Str::replaceLast('Controller', '', class_basename($class)).' '.$controllerMethod);
        } else {
            $id = Str::camel(strtolower($method).' '.str_replace(['/', '{', '}'], ' ', $route->uri()));
        }

        // apiResource registra PUT y PATCH sobre la misma acción del controlador,
        // así que el verbo desempata el identificador.
        if (isset($this->usedOperationIds[$id])) {
            $id = Str::camel($id.' '.strtolower($method));
        }

        $this->usedOperationIds[$id] = true;

        return $id;
    }

    private function summaryFor(RoutingRoute $route, ?ReflectionMethod $reflection): string
    {
        $docblock = $reflection?->getDocComment();

        if (is_string($docblock)) {
            foreach (explode("\n", $docblock) as $line) {
                $line = trim($line, " \t*/");

                if ($line !== '' && ! Str::startsWith($line, '@')) {
                    return $line;
                }
            }
        }

        if ($reflection !== null) {
            $resource = $this->tagFor($route);
            $verb = match ($reflection->getName()) {
                'index' => 'Listar',
                'store' => 'Crear',
                'show' => 'Obtener',
                'update' => 'Actualizar',
                'destroy' => 'Eliminar',
                default => Str::ucfirst(Str::lower(Str::headline($reflection->getName()))),
            };

            return "{$verb} {$resource}";
        }

        return $route->uri();
    }

    /**
     * @param  array<string, array<string, mixed>>  $paths
     * @return list<array<string, string>>
     */
    private function buildTags(array $paths): array
    {
        $tags = collect($paths)
            ->flatMap(fn (array $operations) => collect($operations)->pluck('tags')->flatten())
            ->unique()
            ->sort()
            ->values();

        return $tags->map(fn (string $tag) => ['name' => $tag])->all();
    }
}
