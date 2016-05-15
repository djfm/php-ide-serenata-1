<?php

namespace PhpIntegrator;

use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionFunction;
use ReflectionFunctionAbstract;

/**
 * Handles indexation of built-in classes, global constants and global functions.
 */
class BuiltinIndexer
{
    /**
     * The storage to use for index data.
     *
     * @var Indexer\StorageInterface
     */
    protected $storage;

    /**
     * @var array
     */
    protected $accessModifierMap;

    /**
     * @var array
     */
    protected $structureTypeMap;

    /**
     * Whether to display (debug) output.
     *
     * @var bool
     */
    protected $showOutput;

    /**
     * @param Indexer\StorageInterface $storage
     * @param bool                     $showOutput
     */
    public function __construct(Indexer\StorageInterface $storage, $showOutput)
    {
        $this->storage = $storage;
        $this->showOutput = $showOutput;
    }

    /**
     * Logs a single message for debugging purposes.
     *
     * @param string $message
     */
    protected function logMessage($message)
    {
        if (!$this->showOutput) {
            return;
        }

        echo $message . PHP_EOL;
    }

    /**
     * Indexes built-in classes, global functions and global constants.
     */
    public function index()
    {
        $this->storage->beginTransaction();

        try {
            $this->logMessage('Indexing built-in constants...');
            $this->indexBuiltinConstants();

            $this->logMessage('Indexing built-in functions...');
            $this->indexBuiltinFunctions();

            $this->logMessage('Indexing built-in classes...');
            $this->indexBuiltinStructures();

            $this->storage->commitTransaction();
        } catch (Exception $e) {
            $this->storage->rollbackTransaction();

            throw $e;
        }
    }

    /**
     * Indexes built-in PHP constants.
     */
    protected function indexBuiltinConstants()
    {
        foreach (get_defined_constants(true) as $namespace => $constantList) {
            if ($namespace === 'user') {
                continue; // User constants are indexed in the outline.
            }

            // NOTE: Be very careful if you want to pass back the value, there are also escaped paths, newlines
            // (PHP_EOL), etc. in there.
            foreach ($constantList as $name => $value) {
                $this->indexBuiltinConstant($name);
            }
        }
    }

    /**
     * @param string $name
     *
     * @return int
     */
    protected function indexBuiltinConstant($name)
    {
        return $this->storage->insert(IndexStorageItemEnum::CONSTANTS, [
            'name'               => $name,
            'fqcn'              => $name,
            'file_id'            => null,
            'start_line'         => null,
            'end_line'           => null,
            'is_builtin'         => 1,
            'is_deprecated'      => 0,
            'has_docblock'       => 0,
            'short_description'  => null,
            'long_description'   => null,
            'type_description'   => null,
            'types_serialized'   => serialize([])
        ]);
    }

    /**
     * Indexes built-in PHP functions.
     */
    protected function indexBuiltinFunctions()
    {
        foreach (get_defined_functions() as $group => $functions) {
            foreach ($functions as $functionName) {
                $function = null;

                try {
                    $function = new ReflectionFunction($functionName);
                } catch (\Exception $e) {
                    $this->logMessage(
                        '  - WARNING: Could not examine built-in function ' . $function->getName() . ' with Reflection'
                    );

                    continue;
                }

                $this->indexBuiltinFunctionLike($function);
            }
        }
    }

    /**
     * @param ReflectionFunctionAbstract $function
     *
     * @return int
     */
    protected function indexBuiltinFunctionLike(ReflectionFunctionAbstract $function)
    {
        $returnTypes = [];

        // Requires PHP >= 7.
        if (method_exists($function, 'getReturnType')) {
            $returnType = $function->getReturnType();

            if ($returnType) {
                $returnTypes[] = [
                    'type' => (string) $returnType,
                    'fqcn' => (string) $returnType
                ];
            }
        }

        $functionId = $this->storage->insert(IndexStorageItemEnum::FUNCTIONS, [
            'name'                    => $function->getName(),
            'fqcn'                   => null,
            'file_id'                 => null,
            'start_line'              => null,
            'end_line'                => null,
            'is_builtin'              => 1,
            'is_deprecated'           => $function->isDeprecated() ? 1 : 0,
            'short_description'       => null,
            'long_description'        => null,
            'return_description'      => null,
            'return_type_hint'        => null,
            'structure_id'            => null,
            'access_modifier_id'      => null,
            'is_magic'                => 0,
            'is_static'               => 0,
            'has_docblock'            => 0,
            'throws_serialized'       => serialize([]),
            'parameters_serialized'   => serialize([]),
            'return_types_serialized' => serialize($returnTypes)
        ]);

        $parameters = [];

        foreach ($function->getParameters() as $parameter) {
            $isVariadic = false;

            // Requires PHP >= 5.6.
            if (method_exists($parameter, 'isVariadic')) {
                $isVariadic = $parameter->isVariadic();
            }

            $types = [];

            // Requires PHP >= 7, good thing this only affects built-in functions, which don't have any type
            // hinting yet anyways (at least in PHP < 7).
            if (method_exists($parameter, 'getType')) {
                $type = $parameter->getType();

                if ($type) {
                    $types[] = [
                        'type' => (string) $type,
                        'fqcn' => (string) $type
                    ];
                }
            }

            $parameterData = [
                'function_id'      => $functionId,
                'name'             => $parameter->getName(),
                'type_hint'        => null,
                'types_serialized' => serialize($types),
                'description'      => null,
                'is_nullable'      => $type && $type->allowsNull() ? 1 : 0,
                'is_reference'     => $parameter->isPassedByReference() ? 1 : 0,
                'is_optional'      => $parameter->isOptional() ? 1 : 0,
                'is_variadic'      => $isVariadic ? 1 : 0
            ];

            if (!isset($parameterData['name'])) {
                $this->logMessage(
                    '  - WARNING: Ignoring malformed function parameters for ' . $function->getName()
                );

                // Some PHP extensions somehow contain parameters that have no name. An example of this is
                // ssh2_poll (from the ssh2 extension). Strangely enough this mystery function also can't be
                // found in the documentation. (Perhaps a bug in the extension?) Ignore these.
                continue;
            }

            $this->storage->insert(IndexStorageItemEnum::FUNCTIONS_PARAMETERS, $parameterData);

            $parameters[] = $parameterData;
        }

        $this->storage->update(IndexStorageItemEnum::FUNCTIONS, $functionId, [
            'throws_serialized'     => serialize([]),
            'parameters_serialized' => serialize($parameters)
        ]);

        return $functionId;
    }

    /**
     * Indexes built-in PHP classes, interfaces and traits.
     */
    protected function indexBuiltinStructures()
    {
        foreach (get_declared_traits() as $trait) {
            $element = new ReflectionClass($trait);

            if ($element->isInternal()) {
                $this->indexBuiltinStructure($element);
            }
        }

        foreach (get_declared_interfaces() as $interface) {
            $element = new ReflectionClass($interface);

            if ($element->isInternal()) {
                $this->indexBuiltinStructure($element);
            }
        }

        foreach (get_declared_classes() as $class) {
            $element = new ReflectionClass($class);

            if ($element->isInternal()) {
                $this->indexBuiltinStructure($element);
            }
        }
    }

    /**
     * Indexes the specified built-in class, interface or trait.
     *
     * @param ReflectionClass $element
     */
    protected function indexBuiltinStructure(ReflectionClass $element)
    {
        $type = null;
        $parents = [];
        $interfaces = [];
        $traits = $element->getTraitNames();

        if ($element->isTrait()) {
            $type = 'trait';
            $interfaces = [];
            $parents = [];
        } elseif ($element->isInterface()) {
            $type = 'interface';
            $interfaces = [];

            // 'getParentClass' only returns one extended interface. If an interface extends multiple interfaces, the
            // other ones instead show up in 'getInterfaceNames'.
            $parents = $element->getInterfaceNames();
        } else {
            $type = 'class';
            $interfaces = $element->getInterfaceNames();
            $parents = $element->getParentClass() ? [$element->getParentClass()->getName()] : [];
        }

        $structureTypeMap = $this->getStructureTypeMap();

        $structureId = $this->storage->insert(IndexStorageItemEnum::STRUCTURES, [
            'name'              => $element->getShortName(),
            'fqcn'             => $element->getName(),
            'file_id'           => null,
            'start_line'        => null,
            'end_line'          => null,
            'structure_type_id' => $structureTypeMap[$type],
            'short_description' => null,
            'long_description'  => null,
            'is_builtin'        => 1,
            'is_abstract'       => $element->isAbstract() ? 1 : 0,
            'is_annotation'     => 0,
            'is_deprecated'     => 0,
            'has_docblock'      => 0
        ]);

        foreach ($parents as $parent) {
            $this->storage->insert(IndexStorageItemEnum::STRUCTURES_PARENTS_LINKED, [
                'structure_id'           => $structureId,
                'linked_structure_fqcn' => $parent
            ]);
        }

        foreach ($interfaces as $interface) {
            $this->storage->insert(IndexStorageItemEnum::STRUCTURES_INTERFACES_LINKED, [
                'structure_id'           => $structureId,
                'linked_structure_fqcn' => $interface
            ]);
        }

        foreach ($traits as $trait) {
            $this->storage->insert(IndexStorageItemEnum::STRUCTURES_TRAITS_LINKED, [
                'structure_id'           => $structureId,
                'linked_structure_fqcn' => $trait
            ]);
        }

        foreach ($element->getMethods() as $method) {
            $this->indexBuiltinMethod($method, $structureId);
        }

        foreach ($element->getProperties() as $property) {
            $this->indexBuiltinProperty($property, $structureId);
        }

        foreach ($element->getConstants() as $constantName => $constantValue) {
            $this->indexBuiltinClassConstant($constantName, $structureId);
        }
    }

    /**
     * @param ReflectionMethod $method
     * @param int              $structureId
     */
    protected function indexBuiltinMethod(ReflectionMethod $method, $structureId)
    {
        $functionId = $this->indexBuiltinFunctionLike($method);

        $accessModifierName = null;

        if ($method->isPublic()) {
            $accessModifierName = 'public';
        } elseif ($method->isProtected()) {
            $accessModifierName = 'public';
        } else/*if ($method->isPrivate())*/ {
            $accessModifierName = 'private';
        }

        $accessModifierMap = $this->getAccessModifierMap();

        $this->storage->update(IndexStorageItemEnum::FUNCTIONS, $functionId, [
            'structure_id'       => $structureId,
            'access_modifier_id' => $accessModifierMap[$accessModifierName],
            'is_static'          => $method->isStatic()
        ]);
    }

    /**
     * @param ReflectionProperty $property
     * @param int                $structureId
     */
    protected function indexBuiltinProperty(ReflectionProperty $property, $structureId)
    {
        $accessModifierName = null;

        if ($property->isPublic()) {
            $accessModifierName = 'public';
        } elseif ($property->isProtected()) {
            $accessModifierName = 'public';
        } else/*if ($property->isPrivate())*/ {
            $accessModifierName = 'private';
        }

        $accessModifierMap = $this->getAccessModifierMap();

        $this->storage->insert(IndexStorageItemEnum::PROPERTIES, [
            'name'               => $property->getName(),
            'file_id'            => null,
            'start_line'         => null,
            'end_line'           => null,
            'is_deprecated'      => 0,
            'is_magic'           => 0,
            'is_static'          => $property->isStatic(),
            'has_docblock'       => 0,
            'short_description'  => null,
            'long_description'   => null,
            'type_description'   => null,
            'structure_id'       => $structureId,
            'access_modifier_id' => $accessModifierMap[$accessModifierName],
            'types_serialized'   => serialize([])
        ]);
    }

    /**
     * @param string $name
     * @param int    $structureId
     */
    protected function indexBuiltinClassConstant($name, $structureId)
    {
        $constantId = $this->indexBuiltinConstant($name);

        $this->storage->update(IndexStorageItemEnum::CONSTANTS, $constantId, [
            'structure_id' => $structureId
        ]);
    }

    /**
     * @return array
     */
    protected function getAccessModifierMap()
    {
        if (!$this->accessModifierMap) {
            $this->accessModifierMap = $this->storage->getAccessModifierMap();
        }

        return $this->accessModifierMap;
    }

    /**
     * @return array
     */
    protected function getStructureTypeMap()
    {
        if (!$this->structureTypeMap) {
            $this->structureTypeMap = $this->storage->getStructureTypeMap();
        }

        return $this->structureTypeMap;
    }
}
