<?php declare(strict_types=1);

namespace Rector\NetteToSymfony\Rector\ClassMethod;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\NetteToSymfony\Annotation\RouteTagValueNode;
use Rector\NetteToSymfony\Route\RouteInfo;
use Rector\NetteToSymfony\Route\RouteInfoFactory;
use Rector\NodeTypeResolver\Application\ClassLikeNodeCollector;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockAnalyzer;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\PhpParser\Node\Maintainer\ClassMaintainer;
use Rector\PhpParser\Node\Maintainer\ClassMethodMaintainer;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;
use Rector\Util\RectorStrings;
use ReflectionMethod;

/**
 * @see https://doc.nette.org/en/2.4/routing
 * @see https://symfony.com/doc/current/routing.html
 */
final class RouterListToControllerAnnotationsRector extends AbstractRector
{
    /**
     * @var string
     */
    private $routeListClass;

    /**
     * @var string
     */
    private $routerClass;

    /**
     * @var string
     */
    private $routeAnnotationClass;

    /**
     * @var BetterNodeFinder
     */
    private $betterNodeFinder;

    /**
     * @var ClassLikeNodeCollector
     */
    private $classLikeNodeCollector;

    /**
     * @var ClassMaintainer
     */
    private $classMaintainer;

    /**
     * @var DocBlockAnalyzer
     */
    private $docBlockAnalyzer;

    /**
     * @var RouteInfoFactory
     */
    private $routeInfoFactory;

    /**
     * @var ClassMethodMaintainer
     */
    private $classMethodMaintainer;

    public function __construct(
        BetterNodeFinder $betterNodeFinder,
        ClassLikeNodeCollector $classLikeNodeCollector,
        ClassMaintainer $classMaintainer,
        ClassMethodMaintainer $classMethodMaintainer,
        DocBlockAnalyzer $docBlockAnalyzer,
        RouteInfoFactory $routeInfoFactory,
        string $routeListClass = 'Nette\Application\Routers\RouteList',
        string $routerClass = 'Nette\Application\IRouter',
        string $routeAnnotationClass = 'Symfony\Component\Routing\Annotation\Route'
    ) {
        $this->routeListClass = $routeListClass;
        $this->routerClass = $routerClass;
        $this->betterNodeFinder = $betterNodeFinder;
        $this->classLikeNodeCollector = $classLikeNodeCollector;
        $this->classMaintainer = $classMaintainer;
        $this->docBlockAnalyzer = $docBlockAnalyzer;
        $this->routeAnnotationClass = $routeAnnotationClass;
        $this->routeInfoFactory = $routeInfoFactory;
        $this->classMethodMaintainer = $classMethodMaintainer;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Change new Route() from RouteFactory to @Route annotation above controller method',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
final class RouterFactory
{
    public function create(): RouteList
    {
        $routeList = new RouteList();
        $routeList[] = new Route('some-path', SomePresenter::class);

        return $routeList;
    }
}

final class SomePresenter
{
    public function run()
    {
    }
}                
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
final class RouterFactory
{
    public function create(): RouteList
    {
        $routeList = new RouteList();

        // case of single action controller, usually get() or __invoke() method
        $routeList[] = new Route('some-path', SomePresenter::class);

        return $routeList;
    }
}

final class SomePresenter
{
    /**
     * @Symfony\Component\Routing\Annotation\Route(path="some-path")
     */
    public function run()
    {
    }
}                
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * List of nodes this class checks, classes that implement @see \PhpParser\Node
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    /**
     * @param ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        if (empty($node->stmts)) {
            return null;
        }

        $nodeReturnTypes = $this->classMethodMaintainer->resolveReturnType($node);
        if ($nodeReturnTypes === []) {
            return null;
        }

        if (! in_array($this->routeListClass, $nodeReturnTypes, true)) {
            return null;
        }

        $assignNodes = $this->resolveAssignRouteNodes($node);
        if ($assignNodes === []) {
            return null;
        }

        $routeInfos = $this->createRouteInfosFromAssignNodes($assignNodes);

        /** @var RouteInfo $routeInfo */
        foreach ($routeInfos as $routeInfo) {
            $classMethod = $this->resolveControllerClassMethod($routeInfo);
            if ($classMethod === null) {
                continue;
            }

            $phpDocTagNode = new RouteTagValueNode(
                $this->routeAnnotationClass,
                $routeInfo->getPath(),
                null,
                $routeInfo->getHttpMethods()
            );

            $this->docBlockAnalyzer->addTag($classMethod, $phpDocTagNode);
        }

        // complete all other non-explicit methods, from "<presenter>/<action>"
        $this->completeImplicitRoutes();

        // remove routes
        $this->removeNodes($assignNodes);

        return null;
    }

    /**
     * @return Assign[]
     */
    private function resolveAssignRouteNodes(ClassMethod $node): array
    {
        // look for <...>[] = IRoute<Type>
        return $this->betterNodeFinder->find($node->stmts, function (Node $node) {
            if (! $node instanceof Assign) {
                return false;
            }

            // $routeList[] =
            if (! $node->var instanceof ArrayDimFetch) {
                return false;
            }

            if ($this->isType($node->expr, $this->routerClass)) {
                return true;
            }

            if ($node->expr instanceof StaticCall) {
                // for custom static route factories
                return $this->isRouteStaticCallMatch($node->expr);
            }

            return false;
        });
    }

    /**
     * @param Assign[] $assignNodes
     * @return RouteInfo[]
     */
    private function createRouteInfosFromAssignNodes(array $assignNodes): array
    {
        $routeInfos = [];

        // collect annotations and target controllers
        foreach ($assignNodes as $assignNode) {
            $routeNameToControllerMethod = $this->routeInfoFactory->createFromNode($assignNode->expr);
            if ($routeNameToControllerMethod === null) {
                continue;
            }

            $routeInfos[] = $routeNameToControllerMethod;
        }

        return $routeInfos;
    }

    private function resolveControllerClassMethod(RouteInfo $routeInfo): ?ClassMethod
    {
        $classNode = $this->classLikeNodeCollector->findClass($routeInfo->getClass());
        if ($classNode === null) {
            return null;
        }

        return $this->classMaintainer->getMethodByName($classNode, $routeInfo->getMethod());
    }

    private function completeImplicitRoutes(): void
    {
        $presenterClassNodes = $this->classLikeNodeCollector->findClassesBySuffix('Presenter');

        foreach ($presenterClassNodes as $presenterClassNode) {
            foreach ((array) $presenterClassNode->stmts as $classStmt) {
                if ($this->shouldSkipClassStmt($classStmt)) {
                    continue;
                }

                /** @var ClassMethod $classStmt */
                $path = $this->resolvePathFromClassAndMethodNodes($presenterClassNode, $classStmt);
                $phpDocTagNode = new RouteTagValueNode($this->routeAnnotationClass, $path);

                $this->docBlockAnalyzer->addTag($classStmt, $phpDocTagNode);
            }
        }
    }

    /**
     * @todo allow extension with custom resolvers
     */
    private function isRouteStaticCallMatch(StaticCall $node): bool
    {
        $className = $this->getName($node->class);
        if ($className === null) {
            return false;
        }

        $methodName = $this->getName($node->name);
        if ($methodName === null) {
            return false;
        }

        // @todo decouple - resolve method return type
        if (! method_exists($className, $methodName)) {
            return false;
        }

        $methodReflection = new ReflectionMethod($className, $methodName);
        if ($methodReflection->getReturnType() !== null) {
            $staticCallReturnType = (string) $methodReflection->getReturnType();
            if (is_a($staticCallReturnType, $this->routerClass, true)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkipClassStmt(Node $node): bool
    {
        if (! $node instanceof ClassMethod) {
            return true;
        }

        // not an action method
        if (! $node->isPublic()) {
            return true;
        }

        if (! $this->matchName($node, '#^(render|action)#')) {
            return true;
        }

        // already has Route tag
        return $this->docBlockAnalyzer->hasTag($node, $this->routeAnnotationClass);
    }

    private function resolvePathFromClassAndMethodNodes(Class_ $classNode, ClassMethod $classMethodNode): string
    {
        $presenterName = $this->getName($classNode);
        $presenterPart = Strings::after($presenterName, '\\', -1);
        $presenterPart = Strings::substring($presenterPart, 0, -Strings::length('Presenter'));
        $presenterPart = RectorStrings::camelCaseToDashes($presenterPart);

        $match = Strings::match($this->getName($classMethodNode), '#^(action|render)(?<short_action_name>.*?$)#sm');
        $actionPart = lcfirst($match['short_action_name']);

        return $presenterPart . '/' . $actionPart;
    }
}