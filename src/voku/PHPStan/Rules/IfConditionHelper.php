<?php

declare(strict_types=1);

namespace voku\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\Broker;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\VerbosityLevel;

final class IfConditionHelper
{
    /**
     * @param \PhpParser\Node\Expr $cond
     * @param array<int, class-string> $classesNotInIfConditions
     *
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    public static function processBooleanNodeHelper(
        Node  $cond,
        Scope $scope,
        array $classesNotInIfConditions,
        Node  $origNode,
        ?ReflectionProvider $reflectionProvider = null
    ): array
    {
        // init
        $errors = [];

        // ignore mixed types
        $condType = $scope->getType($cond);
        if ($condType instanceof \PHPStan\Type\MixedType) {
            return [];
        }

        if (
            !property_exists($cond, 'left')
            &&
            !property_exists($cond, 'right')
        ) {
            $errors = self::processNodeHelper(
                $condType,
                null,
                $origNode,
                $errors,
                $classesNotInIfConditions,
                $origNode
            );

            return $errors;
        }

        if (property_exists($cond, 'left')) {
            $leftType = $scope->getType($cond->left);
        } else {
            $leftType = null;
        }

        if (property_exists($cond, 'right')) {
            $rightType = $scope->getType($cond->right);
        } else {
            $rightType = null;
        }

        // left <-> right
        $errors = self::processNodeHelper(
            $leftType,
            $rightType,
            $cond,
            $errors,
            $classesNotInIfConditions,
            $origNode,
            $reflectionProvider
        );
        // right <-> left
        $errors = self::processNodeHelper(
            $rightType,
            $leftType,
            $cond,
            $errors,
            $classesNotInIfConditions,
            $origNode,
            $reflectionProvider
        );

        return $errors;
    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param \PHPStan\Type\Type|null $type_2
     * @param \PhpParser\Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     * @param array<int, class-string> $classesNotInIfConditions
     *
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    public static function processNodeHelper(
        ?\PHPStan\Type\Type $type_1,
        ?\PHPStan\Type\Type $type_2,
        Node                $cond,
        array               $errors,
        array               $classesNotInIfConditions,
        Node                $origNode,
        ?ReflectionProvider $reflectionProvider = null
    ): array
    {

        // DEBUG
        //var_dump(get_class($type_1), get_class($cond), get_class($type_2));

        // -----------------------------------------------------------------------------------------

        self::processCheckOnArray($type_1, $cond, $errors, $origNode);

        // -----------------------------------------------------------------------------------------

        self::processObjectMethodUsageForComparison($type_1, $cond, $errors, $classesNotInIfConditions, $origNode);

        // -----------------------------------------------------------------------------------------

        self::processEqualRules($type_1, $type_2, $cond, $errors, $origNode);

        // -----------------------------------------------------------------------------------------

        self::processNotEqualRules($type_1, $type_2, $cond, $errors, $origNode);

        // -----------------------------------------------------------------------------------------

        self::processBooleanComparison($type_1, $type_2, $cond, $errors, $origNode);

        // -----------------------------------------------------------------------------------------

        self::processObjectComparison($type_1, $type_2, $cond, $errors, $origNode, $reflectionProvider);

        // -----------------------------------------------------------------------------------------

        self::processNonEmptyStrings($type_1, $type_2, $cond, $errors, $origNode);

        // -----------------------------------------------------------------------------------------

        self::processInsaneComparison($type_1, $type_2, $cond, $errors, $origNode);

        // -----------------------------------------------------------------------------------------

        return $errors;
    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param \PHPStan\Type\Type|null $type_2
     * @param Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     */
    private static function processEqualRules(
        ?\PHPStan\Type\Type $type_1,
        ?\PHPStan\Type\Type $type_2,
        Node                $cond,
        array               &$errors,
        Node                $origNode
    ): void
    {
        if (!$cond instanceof \PhpParser\Node\Expr\BinaryOp\Equal) {
            return;
        }

        if (
            $type_1 instanceof \PHPStan\Type\Constant\ConstantStringType
            &&
            $type_1->getValue() === ''
            &&
            (
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\IntegerType::class)
                ||
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\FloatType::class)
            )
        ) {
            $errors[] = self::buildErrorMessage($origNode, 'Please do not use empty-string check for numeric values. e.g. `0 == \'\'` is not working with >= PHP 8.', $cond->getAttribute('startLine'));
        }
    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param \PHPStan\Type\Type|null $type_2
     * @param Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     */
    private static function processNotEqualRules(
        ?\PHPStan\Type\Type $type_1,
        ?\PHPStan\Type\Type $type_2,
        Node                $cond,
        array               &$errors,
        Node                $origNode
    ): void
    {
        if (!$cond instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual) {
            return;
        }

        if (
            $type_1 instanceof \PHPStan\Type\Constant\ConstantStringType
            &&
            $type_1->getValue() === ''
            &&
            (
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\IntegerType::class)
                ||
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\FloatType::class)
            )
        ) {
            $errors[] = self::buildErrorMessage($origNode, 'Please do not use empty-string check for numeric values. e.g. `0 != \'\'` is not working with >= PHP 8.', $cond->getAttribute('startLine'));
        }

        if (
            $type_1 instanceof \PHPStan\Type\Constant\ConstantStringType
            &&
            $type_1->getValue() === ''
            &&
            $type_2 instanceof \PHPStan\Type\StringType
        ) {
            $errors[] = self::buildErrorMessage($origNode, 'Please do not use double negative string conditions. e.g. `(string)$foo != \'\'` is the same as `(string)$foo`.', $cond->getAttribute('startLine'));
        }

        if (
            (
                ($type_1 instanceof \PHPStan\Type\Constant\ConstantStringType && $type_1->getValue() === '')
                ||
                ($type_1 instanceof \PHPStan\Type\Constant\ConstantIntegerType && $type_1->getValue() === 0)
                ||
                ($type_1 instanceof \PHPStan\Type\Constant\ConstantBooleanType && $type_1->getValue() === false)
            )
            &&
            self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\IntegerType::class, false)
        ) {
            $errors[] = self::buildErrorMessage($origNode, 'Please do not use double negative integer conditions. e.g. `(int)$foo != 0` is the same as `(int)$foo`.', $cond->getAttribute('startLine'));
        }

        if (
            (
                ($type_1 instanceof \PHPStan\Type\Constant\ConstantStringType && $type_1->getValue() === '')
                ||
                ($type_1 instanceof \PHPStan\Type\Constant\ConstantIntegerType && $type_1->getValue() === 0)
                ||
                ($type_1 instanceof \PHPStan\Type\Constant\ConstantBooleanType && $type_1->getValue() === false)
            )
            &&
            self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\BooleanType::class)
        ) {
            $errors[] = self::buildErrorMessage($origNode, 'Please do not use double negative boolean conditions. e.g. `(bool)$foo != false` is the same as `(bool)$foo`.', $cond->getAttribute('startLine'));
        }

        // NULL checks are difficult and maybe unexpected, so that we should use strict check here
        // https://3v4l.org/a4VdC
        if (
            $type_1 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_1->getValue() === null
            &&
            $type_2 instanceof \PHPStan\Type\IntegerType
        ) {
            $errors[] = self::buildErrorMessage($origNode, 'Please do not use double negative null conditions. Use "!==" instead if needed.', $cond->getAttribute('startLine'));
        }
    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param \PHPStan\Type\Type|null $type_2
     * @param Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     */
    private static function processBooleanComparison(
        ?\PHPStan\Type\Type $type_1,
        ?\PHPStan\Type\Type $type_2,
        Node                $cond,
        array               &$errors,
        Node                $origNode
    ): void
    {
        if (!$type_1 instanceof \PHPStan\Type\BooleanType) {
            return;
        }

        if ($type_2 instanceof \PHPStan\Type\Constant\ConstantIntegerType) {
            $errors[] = self::buildErrorMessage($origNode, 'Do not compare boolean and integer.', $cond->getAttribute('startLine'));
        }

        if ($type_2 instanceof \PHPStan\Type\Constant\ConstantStringType) {
            $errors[] = self::buildErrorMessage($origNode, 'Do not compare boolean and string.', $cond->getAttribute('startLine'));
        }
    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param \PHPStan\Type\Type|null $type_2
     * @param Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     */
    private static function processObjectComparison(
        ?\PHPStan\Type\Type $type_1,
        ?\PHPStan\Type\Type $type_2,
        Node                $cond,
        array               &$errors,
        Node                $origNode,
        ?ReflectionProvider $reflectionProvider = null
    ): void
    {
        if (
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\Identical
            ||
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical
            ||
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\Coalesce
            ||
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\BooleanAnd
            ||
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\BooleanOr
            ||
            $origNode instanceof \PHPStan\Node\BooleanAndNode
            ||
            $origNode instanceof \PHPStan\Node\BooleanOrNode
        ) {
            return;
        }

        if (!self::isObjectOrNullType($type_1)) {
            return;
        }

        if ($type_1 instanceof \PHPStan\Type\NullType) {
            return;
        }

        if (!$type_2) {
            return;
        }

        if ($type_1->equals($type_2)) {
            return;
        }
        
        $errorFound = false;
        if (
            (
                $cond instanceof \PhpParser\Node\Expr\BinaryOp\Concat
                ||
                $cond instanceof \PhpParser\Node\Expr\AssignOp\Concat
            )
            &&
            $reflectionProvider
        ) {
            $referencedClasses = TypeUtils::getDirectClassNames($type_1);
            
            foreach ($referencedClasses as $referencedClass) {
                try {
                    $classReflection = $reflectionProvider->getClass($referencedClass);
                    $classReflection->getNativeMethod('__toString');

                    $errors[] = self::buildErrorMessage(
                        $origNode,
                        sprintf('Do not cast objects magically, please use `__toString` here, %s and %s found.', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                        $cond->getAttribute('startLine')
                    );

                    $errorFound = true;
                } catch (\PHPStan\Broker\ClassNotFoundException $e) {
                    // Other rules will notify if the class is not found
                } catch (\PHPStan\Reflection\MissingMethodFromReflectionException $e) {
                    // Other rules will notify if the method is not found
                }
            }
        } 
        
        if ($errorFound === false) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Do not compare objects directly, %s and %s found.', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
        }
    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param \PHPStan\Type\Type|null $type_2
     * @param Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     */
    private static function processNonEmptyStrings(
        ?\PHPStan\Type\Type $type_1,
        ?\PHPStan\Type\Type $type_2,
        Node                $cond,
        array               &$errors,
        Node                $origNode
    ): void
    {
        if (
            !(
                $type_1 instanceof \PHPStan\Type\Constant\ConstantStringType
                &&
                $type_1->getValue() === ''
                &&
                $type_2
                &&
                $type_2->isNonEmptyString()->yes()
            )
        ) {
            return;
        }

        if (
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual
            ||
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical
        ) {
            $errors[] = self::buildErrorMessage($origNode, 'Non-empty string is never empty.', $cond->getAttribute('startLine'));
        }

        if (
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\Equal
            ||
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\Identical
        ) {
            $errors[] = self::buildErrorMessage($origNode, 'Non-empty string is always non-empty.', $cond->getAttribute('startLine'));
        }
    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param \PHPStan\Type\Type|null $type_2
     * @param Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     */
    private static function processInsaneComparison(
        ?\PHPStan\Type\Type $type_1,
        ?\PHPStan\Type\Type $type_2,
        Node                $cond,
        array               &$errors,
        Node                $origNode
    ): void
    {
        // init
        $possibleInsaneComparisonFound = false;
        
        if (
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\BooleanAnd 
            && 
            $type_2 instanceof \PHPStan\Type\ConstantScalarType
            &&
            !$type_2->getValue()
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Condition between %s and %s are always false.', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
        }
        
        if (
            !$cond instanceof \PhpParser\Node\Expr\BinaryOp\Equal
            &&
            !$cond instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual
            &&
            !$cond instanceof \PhpParser\Node\Expr\BinaryOp\Identical
            &&
            !$cond instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical
        ) {
            return;
        }

        if (
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\Equal
            &&
            $type_1 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_2 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_1->getValue() != $type_2->getValue()
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Insane comparison between %s and %s', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
            
            return;
        }

        if (
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual
            &&
            $type_1 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_2 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_1->getValue() == $type_2->getValue()
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Insane comparison between %s and %s', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
            
            return;
        }

        if (
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\Identical
            &&
            $type_1 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_2 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_1->getValue() !== $type_2->getValue()
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Insane comparison between %s and %s', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
            
            return;
        }

        if (
            $cond instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical
            &&
            $type_1 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_2 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_1->getValue() === $type_2->getValue()
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Insane comparison between %s and %s', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
            
            return;
        }

        if (
            $type_1 instanceof \PHPStan\Type\Constant\ConstantStringType
            &&
            $type_1->isNumericString()->yes()
            &&
            (float)$type_1->getValue() === 0.0
            &&
            $type_2
            &&
            self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\Constant\ConstantIntegerType::class, false)
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Possible insane comparison between %s and %s', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
            
            return;
        }

        if (
            $type_1 instanceof \PHPStan\Type\Constant\ConstantStringType
            &&
            $type_1->isNumericString()->no()
            &&
            $type_2
            &&
            (
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\IntegerType::class, false)
                ||
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\FloatType::class, false)
            )
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Possible insane comparison between %s and %s', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
            
            return;
        }

        if (
            (
                $type_1 instanceof \PHPStan\Type\ConstantScalarType
                &&
                $type_1->getValue() !== null
                &&
                \filter_var($type_1->getValue(), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) === null
                &&
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\BooleanType::class, false)
            )
            ||
            (
                $type_1 instanceof \PHPStan\Type\ConstantScalarType
                &&
                $type_1->getValue() !== null
                &&
                \filter_var($type_1->getValue(), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE) === null
                &&
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\IntegerType::class, false)
            )
            ||
            (
                $type_1 instanceof \PHPStan\Type\ConstantScalarType
                &&
                $type_1->getValue() !== null
                &&
                \filter_var($type_1->getValue(), \FILTER_VALIDATE_FLOAT, \FILTER_NULL_ON_FAILURE) === null
                &&
                self::isPhpStanTypeMaybeWithUnionNullable($type_2, \PHPStan\Type\FloatType::class, false)
            )
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Possible insane comparison between %s and %s', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
            
            return;
        }

        if (
            $type_1 instanceof \PHPStan\Type\ConstantScalarType
            &&
            $type_1->getValue() === null
            &&
            !($type_2 instanceof \PHPStan\Type\MixedType)
            &&
            $type_2->isSuperTypeOf(new \PHPStan\Type\NullType())->no()
        ) {
            $errors[] = self::buildErrorMessage(
                $origNode,
                sprintf('Possible insane comparison between %s and %s', $type_1->describe(VerbosityLevel::value()), $type_2->describe(VerbosityLevel::value())),
                $cond->getAttribute('startLine')
            );
        }

    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     */
    private static function processCheckOnArray(
        ?\PHPStan\Type\Type $type_1,
        Node                $cond,
        array               &$errors,
        Node $origNode
    ): void
    {
        if (
            $cond instanceof \PhpParser\Node\Expr\Ternary
            ||
            $cond instanceof \PhpParser\Node\Expr\BinaryOp
            ||
            $cond instanceof \PhpParser\Node\Expr\AssignOp
        ) {
            return;
        }

        if ($type_1 instanceof \PHPStan\Type\UnionType) {
            $type_1 = $type_1->generalize(\PHPStan\Type\GeneralizePrecision::lessSpecific());
        }

        if ($type_1 instanceof \PHPStan\Type\Accessory\NonEmptyArrayType) {

            $errors[] = self::buildErrorMessage($origNode, 'Non-empty array is never empty.', $cond->getAttribute('startLine'));

        } elseif ($type_1 instanceof \PHPStan\Type\ArrayType) {

            if ($cond instanceof Node\Expr\BooleanNot) {
                $errors[] = self::buildErrorMessage($origNode, 'Use a function e.g. `count($foo) === 0` instead of `!$foo`.', $cond->getAttribute('startLine'));
            } else {
                $errors[] = self::buildErrorMessage($origNode, 'Use a function e.g. `count($foo) > 0` instead of `$foo`.', $cond->getAttribute('startLine'));
            }

        }
    }

    /**
     * @param \PHPStan\Type\Type|null $type_1
     * @param Node $cond
     * @param array<int, \PHPStan\Rules\RuleError> $errors
     * @param array<int, class-string> $classesNotInIfConditions
     */
    private static function processObjectMethodUsageForComparison(
        ?\PHPStan\Type\Type $type_1,
        Node                $cond,
        array               &$errors,
        array               $classesNotInIfConditions,
        Node                $origNode
    ): void
    {
        foreach ($classesNotInIfConditions as $classesNotInIfCondition) {
            if (
                $type_1 instanceof \PHPStan\Type\ObjectType
                &&
                \is_a($type_1->getClassName(), $classesNotInIfCondition, true)
            ) {
                $errors[] = self::buildErrorMessage($origNode, 'Use a method to check the condition e.g. `$foo->value()` instead of `$foo`.', $cond->getAttribute('startLine'));
            }
        }
    }

    /**
     * @param class-string<\PHPStan\Type\Type> $typeClassName
     */
    public static function isPhpStanTypeMaybeWithUnionNullable(
        ?\PHPStan\Type\Type $type, 
        $typeClassName,
        bool $useGeneralizeLessSpecific = true
    ): bool
    {
        if ($type === null) {
            return false;
        }
        
        if ($useGeneralizeLessSpecific) {
            $type = $type->generalize(GeneralizePrecision::lessSpecific());
        }
        
        if (
            $type instanceof $typeClassName
            ||
            (
                $type instanceof \PHPStan\Type\UnionType
                &&
                (
                    (
                        $type->getTypes()[0] instanceof $typeClassName
                        &&
                        $type->getTypes()[1] instanceof \PHPStan\Type\NullType
                    )
                    ||
                    (
                        $type->getTypes()[0] instanceof \PHPStan\Type\NullType
                        &&
                        $type->getTypes()[1] instanceof $typeClassName
                    )
                )
            )
        ) {
            return true;
        }

        return false;
    }

    private static function isObjectOrNullType(?\PHPStan\Type\Type $type): bool
    {
        if (
            $type instanceof \PHPStan\Type\ObjectType
            ||
            $type instanceof \PHPStan\Type\StaticType
            ||
            $type instanceof \PHPStan\Type\NullType
        ) {
            return true;
        }

        if (!$type instanceof \PHPStan\Type\UnionType) {
            return false;
        }

        $return = true;
        foreach ($type->getTypes() as $typeFromUnion) {
            $return = self::isObjectOrNullType($typeFromUnion);
            if (!$return) {
                break;
            }
        }

        return $return;
    }

    public static function buildErrorMessage(
        Node   $origNode,
        string $errorMessage,
        int    $line
    ): \PHPStan\Rules\RuleError
    {
        $origNodeClassName = \get_class($origNode);
        $pos = \strrpos($origNodeClassName, '\\');
        if ($pos === false) {
            $origNodeClassNameSimple = $origNodeClassName;
        } else {
            $origNodeClassNameSimple = \substr($origNodeClassName, $pos + 1);
        }
        
        return \PHPStan\Rules\RuleErrorBuilder::message($origNodeClassNameSimple . ': ' . $errorMessage)->line($line)->build();
    }
}
