<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
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

    /** @var list<Usage> */
    public array $usages = [];

    /** @var list<list<string>> cache-typed property names, one entry per class being walked */
    private array $properties = [];

    /** @var list<list<string>> cache-typed variable names, one entry per function being walked */
    private array $variables = [];

    private readonly Standard $printer;

    public function __construct(private readonly string $file)
    {
        $this->printer = new Standard;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            $this->properties[] = $this->cacheProperties($node);
        } elseif ($node instanceof FunctionLike) {
            $this->variables[] = $this->cacheVariables($node);
        } elseif ($node instanceof StaticCall || $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof FuncCall) {
            $this->record($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            array_pop($this->properties);
        } elseif ($node instanceof FunctionLike) {
            array_pop($this->variables);
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

        $this->usages[] = new Usage($this->file, $call->getStartLine(), $style, $method, $operation, $keyKind, $key);
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

        return match (true) {
            $expr === null => [KeyKind::None, null],
            $expr instanceof String_ => [KeyKind::Exact, $expr->value],
            $this->hasLiteralPart($expr) => [KeyKind::Template, $this->printer->prettyPrintExpr($expr)],
            default => [KeyKind::Unresolved, $this->printer->prettyPrintExpr($expr)],
        };
    }

    private function hasLiteralPart(Expr $expr): bool
    {
        return $expr instanceof String_
            || $expr instanceof InterpolatedString
            || ($expr instanceof Concat && ($this->hasLiteralPart($expr->left) || $this->hasLiteralPart($expr->right)));
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
