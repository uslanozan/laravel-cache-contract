<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/**
 * Collects the cache calls of one file. Expects names already resolved by
 * NameResolver, so "Cache" means the facade and not any class of that name.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
final class CacheCallVisitor extends NodeVisitorAbstract
{
    /** "Cache" and "Redis" alone are the global aliases from config/app.php. */
    private const CACHE_FACADES = ['Cache', 'Illuminate\Support\Facades\Cache'];

    private const REDIS_FACADES = ['Redis', 'Illuminate\Support\Facades\Redis'];

    private const CACHE_TYPES = [
        'Illuminate\Contracts\Cache\Repository',
        'Illuminate\Contracts\Cache\Factory',
        'Illuminate\Cache\Repository',
        'Illuminate\Cache\CacheManager',
    ];

    private const CONTAINER_IDS = ['cache', 'cache.store'];

    /** Calls that hand back another cache object instead of doing an operation. */
    private const RECEIVER_METHODS = ['store', 'driver', 'tags', 'connection'];

    /** A key that is only a hash of its parts has nothing left to read. */
    private const HASH_FUNCTIONS = ['md5', 'sha1', 'crc32', 'hash', 'hash_hmac'];

    /** A part of a template key only known at runtime: {id}. */
    private const PLACEHOLDER = '/\{[^{}]*\}/';

    /** @var list<CacheUsage> */
    public array $usages = [];

    /** @var list<string|null> names of the classes being walked, null for an anonymous one */
    private array $classes = [];

    /** @var list<string|null> names of the functions being walked; a closure repeats the one around it */
    private array $functions = [];

    /** @var list<list<string>> cache-typed property names, one entry per class being walked */
    private array $properties = [];

    /** @var list<list<string>> cache-typed variable names, one entry per function being walked */
    private array $variables = [];

    /** @var list<array<string, Expr>> constant values by name, one entry per class being walked */
    private array $constants = [];

    /** @var list<array<string, Expr>> values of the variables assigned once, one entry per scope being walked */
    private array $assignments = [];

    private readonly Standard $printer;

    public function __construct(private readonly string $file)
    {
        $this->printer = new Standard;
    }

    /**
     * @param  Node[]  $nodes
     */
    public function beforeTraverse(array $nodes): null
    {
        // Code outside any function, such as a routes file, is a scope of its own.
        $this->assignments = [$this->assignmentsIn(array_filter($nodes, fn (Node $node): bool => $node instanceof Stmt), [], [])];

        return null;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            $this->classes[] = $node->namespacedName?->toString();
            $this->properties[] = $this->cacheProperties($node);
            $this->constants[] = $this->constantsOf($node);
        } elseif ($node instanceof FunctionLike) {
            $this->functions[] = match (true) {
                $node instanceof ClassMethod => $node->name->toString(),
                $node instanceof Function_ => $node->namespacedName?->toString() ?? $node->name->toString(),
                default => end($this->functions) ?: null,
            };
            $this->variables[] = $this->cacheVariables($node);
            $this->assignments[] = $this->assignmentsIn(
                $node->getStmts() ?? [],
                $node->getParams(),
                // As in PHP: an arrow function sees the whole scope around it, a closure only its use() list.
                match (true) {
                    $node instanceof ArrowFunction => end($this->assignments) ?: [],
                    $node instanceof Closure => array_intersect_key(
                        end($this->assignments) ?: [],
                        array_flip(array_filter(array_map(fn (ClosureUse $use): mixed => $use->var->name, $node->uses), is_string(...))),
                    ),
                    default => [],
                },
            );
        } elseif ($node instanceof StaticCall || $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof FuncCall) {
            $this->record($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            array_pop($this->classes);
            array_pop($this->properties);
            array_pop($this->constants);
        } elseif ($node instanceof FunctionLike) {
            array_pop($this->functions);
            array_pop($this->variables);
            array_pop($this->assignments);
        }

        return null;
    }

    private function record(StaticCall|MethodCall|NullsafeMethodCall|FuncCall $call): void
    {
        if ($call->isFirstClassCallable()) {
            return;
        }

        if ($call instanceof FuncCall) {
            // cache() without arguments is only a receiver; the chained call is the operation.
            if (! $this->isFunction($call, ['cache']) || $call->getArgs() === []) {
                return;
            }

            $style = CallStyle::Helper;
            $method = 'cache';
            $operation = $call->getArgs()[0]->value instanceof Array_ ? Operation::Write : Operation::Read;
        } else {
            if (! $call->name instanceof Identifier || $this->isReceiverMethod($call)) {
                return;
            }

            $style = $call instanceof StaticCall ? $this->facadeStyle($call->class) : $this->receiverStyle($call->var);
            $method = $call->name->toString();
            $operation = Operation::fromMethod($method);
        }

        if ($style === null) {
            return;
        }

        [$keyKind, $key] = $this->key($call->getArgs()[0]->value ?? null);

        $this->usages[] = new CacheUsage(
            $this->file,
            $call->getStartLine(),
            end($this->classes) ?: null,
            end($this->functions) ?: null,
            $style,
            $method,
            $operation,
            $keyKind,
            $key,
        );
    }

    /**
     * The style of the cache object an expression evaluates to, or null when
     * it is not a cache.
     */
    private function receiverStyle(Expr $expr): ?CallStyle
    {
        if ($expr instanceof StaticCall) {
            return $this->isReceiverMethod($expr) ? $this->facadeStyle($expr->class) : null;
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            return $this->isReceiverMethod($expr) ? $this->receiverStyle($expr->var) : null;
        }

        if ($expr instanceof FuncCall && ! $expr->isFirstClassCallable()) {
            if ($this->isFunction($expr, ['cache'])) {
                return $expr->getArgs() === [] ? CallStyle::Helper : null;
            }

            $id = $expr->getArgs()[0]->value ?? null;

            return $this->isFunction($expr, ['app', 'resolve'])
                && $id instanceof String_ && in_array($id->value, self::CONTAINER_IDS, true)
                    ? CallStyle::Container
                    : null;
        }

        // $app['cache'] or $this->app['cache']: the container used as an array.
        if ($expr instanceof ArrayDimFetch) {
            $container = $expr->var instanceof PropertyFetch ? $expr->var->name : $expr->var;
            $name = $container instanceof Variable ? $container->name : ($container instanceof Identifier ? $container->name : null);

            return $name === 'app' && $expr->dim instanceof String_ && in_array($expr->dim->value, self::CONTAINER_IDS, true)
                ? CallStyle::Container
                : null;
        }

        if ($expr instanceof PropertyFetch) {
            return $expr->var instanceof Variable && $expr->var->name === 'this'
                && $expr->name instanceof Identifier
                && in_array($expr->name->toString(), end($this->properties) ?: [], true)
                    ? CallStyle::Repository
                    : null;
        }

        if ($expr instanceof Variable) {
            return is_string($expr->name) && in_array($expr->name, end($this->variables) ?: [], true)
                ? CallStyle::Repository
                : null;
        }

        return null;
    }

    private function facadeStyle(Node $class): ?CallStyle
    {
        if (! $class instanceof Name) {
            return null;
        }

        return match (true) {
            in_array($class->toString(), self::CACHE_FACADES, true) => CallStyle::Facade,
            in_array($class->toString(), self::REDIS_FACADES, true) => CallStyle::Redis,
            default => null,
        };
    }

    private function isReceiverMethod(StaticCall|MethodCall|NullsafeMethodCall $call): bool
    {
        return $call->name instanceof Identifier
            && in_array($call->name->toLowerString(), self::RECEIVER_METHODS, true);
    }

    /**
     * @param  list<string>  $names  lower case
     */
    private function isFunction(FuncCall $call, array $names): bool
    {
        return $call->name instanceof Name && in_array($call->name->toLowerString(), $names, true);
    }

    /**
     * @return array{KeyKind, string|null}
     */
    private function key(?Expr $expr): array
    {
        if ($expr instanceof Array_) {
            // shortcut: many()/putMany() and cache([...]) report only their first key, list them all once the inventory needs it
            $item = $expr->items[0] ?? null;
            $expr = $item->key ?? $item?->value;
        }

        if ($expr === null) {
            return [KeyKind::None, null];
        }

        $value = $this->follow($expr);

        if ($value instanceof String_) {
            return [KeyKind::Exact, $value->value];
        }

        if ($value instanceof FuncCall && $this->isFunction($value, self::HASH_FUNCTIONS)) {
            return [KeyKind::Opaque, $this->printer->prettyPrintExpr($value)];
        }

        $template = $this->template($expr);

        return match (true) {
            ! $this->hasLiteral($template) => [KeyKind::Unresolved, $this->printer->prettyPrintExpr($expr)],
            preg_match(self::PLACEHOLDER, $template) === 1 => [KeyKind::Template, $template],
            default => [KeyKind::Exact, $template],
        };
    }

    /**
     * The key with its literal parts as written and a {name} placeholder for
     * each part only known at runtime: 'feed:'.$id is "feed:{id}".
     *
     * @param  list<string>  $seen  variables and constants already followed, so a cycle ends
     */
    private function template(Expr $expr, array $seen = []): string
    {
        $found = $this->valueOf($expr);

        if ($found !== null && ! in_array($found[0], $seen, true)) {
            $template = $this->template($found[1], [...$seen, $found[0]]);

            // A variable that holds no literal keeps its own name: {id}, not {input}.
            if ($this->hasLiteral($template)) {
                return $template;
            }
        }

        return match (true) {
            $expr instanceof String_ => $expr->value,
            $expr instanceof InterpolatedString => implode('', array_map(
                fn (Expr|InterpolatedStringPart $part): string => $part instanceof InterpolatedStringPart ? $part->value : $this->template($part, $seen),
                $expr->parts,
            )),
            $expr instanceof Concat => $this->template($expr->left, $seen).$this->template($expr->right, $seen),
            default => ($expr instanceof FuncCall ? $this->sprintf($expr, $seen) : null) ?? '{'.$this->placeholder($expr).'}',
        };
    }

    /**
     * The template of sprintf() with a literal format, null for any other call.
     *
     * @param  list<string>  $seen
     */
    private function sprintf(FuncCall $call, array $seen): ?string
    {
        $format = $call->isFirstClassCallable() ? null : ($call->getArgs()[0]->value ?? null);

        if (! $this->isFunction($call, ['sprintf']) || ! $format instanceof String_) {
            return null;
        }

        $args = array_slice($call->getArgs(), 1);
        $next = 0;

        // %s, %05.2f, %1$s: an optional argument number, flags, then the conversion letter.
        return (string) preg_replace_callback('/%(?:(\d+)\$)?[^a-zA-Z%]*([a-zA-Z%])/', function (array $match) use ($args, $seen, &$next): string {
            if ($match[2] === '%') {
                return '%';
            }

            $arg = $args[$match[1] !== '' ? (int) $match[1] - 1 : $next++] ?? null;

            return $arg === null ? '{?}' : $this->template($arg->value, $seen);
        }, $format->value);
    }

    /** A name for a part only known at runtime: $id, $user->id and $user->id() are all "id". */
    private function placeholder(Expr $expr): string
    {
        $name = match (true) {
            $expr instanceof Cast => $this->placeholder($expr->expr),
            $expr instanceof ArrayDimFetch => $expr->dim instanceof String_ ? $expr->dim->value : null,
            $expr instanceof FuncCall => $expr->name instanceof Name ? $expr->name->getLast() : null,
            $expr instanceof Variable, $expr instanceof PropertyFetch, $expr instanceof NullsafePropertyFetch,
            $expr instanceof StaticPropertyFetch, $expr instanceof MethodCall, $expr instanceof NullsafeMethodCall,
            $expr instanceof StaticCall, $expr instanceof ClassConstFetch => $expr->name,
            default => null,
        };

        return match (true) {
            is_string($name) => $name,
            $name instanceof Identifier => $name->toString(),
            default => '?',
        };
    }

    /** Whether a template has any text besides its placeholders. */
    private function hasLiteral(string $template): bool
    {
        return preg_replace(self::PLACEHOLDER, '', $template) !== '';
    }

    /** What a variable or constant holds, followed through every variable and constant on the way. */
    private function follow(Expr $expr): Expr
    {
        $seen = [];

        while (($found = $this->valueOf($expr)) !== null && ! in_array($found[0], $seen, true)) {
            $seen[] = $found[0];
            $expr = $found[1];
        }

        return $expr;
    }

    /**
     * The value of a variable assigned once in this scope, or of a constant of
     * this class, with a name that tells the two apart.
     *
     * @return array{string, Expr}|null
     */
    private function valueOf(Expr $expr): ?array
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            $value = (end($this->assignments) ?: [])[$expr->name] ?? null;

            return $value === null ? null : ['$'.$expr->name, $value];
        }

        if ($expr instanceof ClassConstFetch && $expr->name instanceof Identifier && $expr->class instanceof Name
            && in_array($expr->class->toLowerString(), ['self', 'static', strtolower((string) end($this->classes))], true)) {
            $value = (end($this->constants) ?: [])[$expr->name->toString()] ?? null;

            return $value === null ? null : ['::'.$expr->name->toString(), $value];
        }

        return null;
    }

    /**
     * @return array<string, Expr>
     */
    private function constantsOf(ClassLike $class): array
    {
        $values = [];

        foreach ($class->getConstants() as $group) {
            foreach ($group->consts as $const) {
                $values[$const->name->toString()] = $const->value;
            }
        }

        return $values;
    }

    /**
     * The value of each variable a scope writes exactly once, by a plain
     * assignment. Writes inside nested closures count too, so a variable a
     * closure changes is never resolved.
     *
     * shortcut: a write through a by-reference argument (preg_match($p, $s, $key)) or $key++ is not counted; count those if a key is ever resolved wrong.
     *
     * @param  Node[]  $stmts
     * @param  Param[]  $params
     * @param  array<string, Expr>  $inherited  what a closure sees of the scope around it; a name the scope writes itself, a parameter included, hides it
     * @return array<string, Expr>
     */
    private function assignmentsIn(array $stmts, array $params, array $inherited): array
    {
        $targets = array_map(fn (Param $param): Expr => $param->var, $params);
        $values = [];

        /** @var list<Assign|AssignOp|AssignRef|Foreach_> $writes */
        $writes = (new NodeFinder)->find($stmts, fn (Node $node): bool => $node instanceof Assign
            || $node instanceof AssignOp || $node instanceof AssignRef || $node instanceof Foreach_);

        foreach ($writes as $node) {
            if ($node instanceof Foreach_) {
                array_push($targets, ...array_filter([$node->keyVar, $node->valueVar]));

                continue;
            }

            $targets[] = $node->var;

            if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
                $values[$node->var->name] = $node->expr;
            }
        }

        // The variables inside a target are written too: [$a, $b] = …, $list[] = ….
        $counts = array_count_values(array_filter(array_map(
            fn (Variable $variable): mixed => $variable->name,
            (new NodeFinder)->findInstanceOf($targets, Variable::class),
        ), is_string(...)));

        return array_filter($values, fn (string $name): bool => $counts[$name] === 1, ARRAY_FILTER_USE_KEY)
            + array_diff_key($inherited, $counts);
    }

    /**
     * Properties typed as a cache, promoted from a cache-typed constructor
     * parameter, or assigned one in the constructor ($this->cache = $cache).
     *
     * @return list<string>
     */
    private function cacheProperties(ClassLike $class): array
    {
        $names = [];

        foreach ($class->getProperties() as $property) {
            if ($this->isCacheType($property->type)) {
                foreach ($property->props as $prop) {
                    $names[] = $prop->name->toString();
                }
            }
        }

        $constructor = $class->getMethod('__construct');

        if ($constructor === null) {
            return $names;
        }

        $parameters = $this->cacheVariables($constructor);

        foreach ($constructor->params as $param) {
            if ($param->isPromoted() && $param->var instanceof Variable && in_array($param->var->name, $parameters, true)) {
                $names[] = $param->var->name;
            }
        }

        foreach ((new NodeFinder)->findInstanceOf($constructor->stmts ?? [], Assign::class) as $assign) {
            if ($assign->var instanceof PropertyFetch && $assign->var->name instanceof Identifier
                && $assign->expr instanceof Variable && in_array($assign->expr->name, $parameters, true)) {
                $names[] = $assign->var->name->toString();
            }
        }

        return $names;
    }

    /**
     * Cache-typed parameters of a function. Closures and arrow functions also
     * see the ones of the function around them.
     *
     * @return list<string>
     */
    private function cacheVariables(FunctionLike $function): array
    {
        $names = $function instanceof Closure || $function instanceof ArrowFunction
            ? (end($this->variables) ?: [])
            : [];

        foreach ($function->getParams() as $param) {
            if ($this->isCacheType($param->type) && $param->var instanceof Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }

    private function isCacheType(?Node $type): bool
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        return $type instanceof Name && in_array($type->toString(), self::CACHE_TYPES, true);
    }
}
