<?php
/**
 * Mock ObjectManager for testing when Magento TestFramework is not available
 */

namespace Magento\Framework\TestFramework\Unit\Helper;

class ObjectManager
{
    private $objects = [];
    
    public function getObject($className, array $arguments = [])
    {
        if (!isset($this->objects[$className])) {
            $this->objects[$className] = $this->createObject($className, $arguments);
        }
        return $this->objects[$className];
    }
    
    public function getCollectionMock($className, array $data)
    {
        $mock = $this->getMockBuilder($className)
            ->disableOriginalConstructor()
            ->getMock();
            
        $mock->method('getItems')->willReturn($data);
        $mock->method('count')->willReturn(count($data));
        
        return $mock;
    }
    
    private function createObject($className, array $arguments = [])
    {
        $reflection = new \ReflectionClass($className);
        
        if (empty($arguments)) {
            return $reflection->newInstanceWithoutConstructor();
        }
        
        return $reflection->newInstanceArgs($arguments);
    }
    
    public function getMockBuilder($className)
    {
        return new class($className) {
            private $className;
            private $methods = [];
            private $constructorArgs = [];
            private $disableConstructor = false;
            
            public function __construct($className)
            {
                $this->className = $className;
            }
            
            public function setMethods(array $methods)
            {
                $this->methods = $methods;
                return $this;
            }
            
            public function setConstructorArgs(array $args)
            {
                $this->constructorArgs = $args;
                return $this;
            }
            
            public function disableOriginalConstructor()
            {
                $this->disableConstructor = true;
                return $this;
            }
            
            public function getMock()
            {
                // This is a simplified mock - in real tests you'd use PHPUnit's mock framework
                return new class() {
                    private $expectations = [];
                    
                    public function method($methodName)
                    {
                        return $this;
                    }
                    
                    public function willReturn($value)
                    {
                        return $this;
                    }
                    
                    public function with(...$args)
                    {
                        return $this;
                    }
                    
                    public function expects($matcher)
                    {
                        return $this;
                    }
                    
                    public function __call($name, $arguments)
                    {
                        return null;
                    }
                };
            }
        };
    }
}
