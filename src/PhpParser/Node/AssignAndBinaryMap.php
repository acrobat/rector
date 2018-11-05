<?php declare(strict_types=1);

namespace Rector\PhpParser\Node;

use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignOp\BitwiseAnd as AssignBitwiseAnd;
use PhpParser\Node\Expr\AssignOp\BitwiseOr as AssignBitwiseOr;
use PhpParser\Node\Expr\AssignOp\BitwiseXor as AssignBitwiseXor;
use PhpParser\Node\Expr\AssignOp\Concat as AssignConcat;
use PhpParser\Node\Expr\AssignOp\Div as AssignDiv;
use PhpParser\Node\Expr\AssignOp\Minus as AssignMinus;
use PhpParser\Node\Expr\AssignOp\Mod as AssignMod;
use PhpParser\Node\Expr\AssignOp\Mul as AssignMul;
use PhpParser\Node\Expr\AssignOp\Plus as AssignPlus;
use PhpParser\Node\Expr\AssignOp\Pow as AssignPow;
use PhpParser\Node\Expr\AssignOp\ShiftLeft as AssignShiftLeft;
use PhpParser\Node\Expr\AssignOp\ShiftRight as AssignShiftRight;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BitwiseAnd;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\BinaryOp\BitwiseXor;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Div;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Mod;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\BinaryOp\Pow;
use PhpParser\Node\Expr\BinaryOp\ShiftLeft;
use PhpParser\Node\Expr\BinaryOp\ShiftRight;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;

final class AssignAndBinaryMap
{
    /**
     * @var string[]
     */
    private $binaryOpToInverseClasses = [
        BooleanOr::class => BooleanAnd::class,
        Identical::class => NotIdentical::class,
        NotIdentical::class => Identical::class,
        Equal::class => NotEqual::class,
        NotEqual::class => Equal::class,
        Greater::class => SmallerOrEqual::class,
        Smaller::class => GreaterOrEqual::class,
        GreaterOrEqual::class => Smaller::class,
        SmallerOrEqual::class => Greater::class,
    ];

    /**
     * @var string[]
     */
    private $assignOpToBinaryOpClasses = [
        AssignBitwiseOr::class => BitwiseOr::class,
        AssignBitwiseAnd::class => BitwiseAnd::class,
        AssignBitwiseXor::class => BitwiseXor::class,
        AssignPlus::class => Plus::class,
        AssignDiv::class => Div::class,
        AssignMul::class => Mul::class,
        AssignMinus::class => Minus::class,
        AssignConcat::class => Concat::class,
        AssignPow::class => Pow::class,
        AssignMod::class => Mod::class,
        AssignShiftLeft::class => ShiftLeft::class,
        AssignShiftRight::class => ShiftRight::class,
    ];

    /**
     * @var string[]
     */
    private $binaryOpToAssignClasses = [];

    public function __construct()
    {
        $this->binaryOpToAssignClasses = array_flip($this->assignOpToBinaryOpClasses);
    }

    public function getAlternative(Node $node): ?string
    {
        $nodeClass = get_class($node);

        if ($node instanceof AssignOp) {
            return $this->assignOpToBinaryOpClasses[$nodeClass] ?? null;
        }

        if ($node instanceof BinaryOp) {
            return $this->binaryOpToAssignClasses[$nodeClass] ?? null;
        }

        return null;
    }

    public function getInversed(BinaryOp $node): ?string
    {
        $nodeClass = get_class($node);

        return $this->binaryOpToInverseClasses[$nodeClass] ?? null;
    }
}
