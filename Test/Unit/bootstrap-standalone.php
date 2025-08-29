<?php
/**
 * Standalone PHPUnit Bootstrap for testing without Magento framework
 */

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base paths
if (!defined('BP')) {
    define('BP', dirname(__DIR__, 2));
}

// Mock Magento constants
if (!defined('CURLOPT_CUSTOMREQUEST')) {
    define('CURLOPT_CUSTOMREQUEST', 10036);
}

// Mock Magento global functions
if (!function_exists('__')) {
    function __($text) {
        return $text;
    }
}

// Create simple mock classes for Magento framework
require_once __DIR__ . '/Mocks/MagentoMocks.php';
require_once __DIR__ . '/Mocks/ResourceMocks.php';
require_once __DIR__ . '/Mocks/AdditionalMocks.php';
require_once __DIR__ . '/Mocks/StorageMocks.php';

// Set up autoloader for our classes
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
