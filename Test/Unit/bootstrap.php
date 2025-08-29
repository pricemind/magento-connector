<?php
/**
 * PHPUnit Bootstrap for Stellion_Pricemind Module
 */

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define Magento root directory
if (!defined('BP')) {
    define('BP', dirname(__DIR__, 2));
}

// Mock Magento Framework classes if not available
if (!class_exists('\Magento\Framework\TestFramework\Unit\Helper\ObjectManager')) {
    require_once __DIR__ . '/Mock/ObjectManager.php';
}

// Set up autoloader for test classes
spl_autoload_register(function ($className) {
    // Convert namespace to file path
    $classFile = str_replace(['\\', '_'], ['/', '/'], $className) . '.php';
    
    // Try to load from app/code directory
    $appCodeFile = BP . '/app/code/' . $classFile;
    if (file_exists($appCodeFile)) {
        require_once $appCodeFile;
        return true;
    }
    
    // Try to load from Test directory
    $testFile = BP . '/Test/Unit/' . $classFile;
    if (file_exists($testFile)) {
        require_once $testFile;
        return true;
    }
    
    return false;
});
