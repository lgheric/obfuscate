<?php
error_reporting(E_ALL);
ini_set('display_errors','on');

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

// 默认白名单配置
const WP_FUNCTIONS = ['add_action', 'add_filter', 'do_action', 'apply_filters'];
const WP_CLASSES = ['WP_Query', 'WP_User'];
const WP_CONSTANTS = ['ABSPATH'];
const WP_GLOBAL_VARS = ['wpdb', 'post'];

// 加载用户配置
function loadConfig(string $path): array {
    if (!file_exists($path)) return [
        'functions' => WP_FUNCTIONS,
        'classes' => WP_CLASSES,
        'constants' => WP_CONSTANTS,
        'globals' => WP_GLOBAL_VARS,
        'exclude_patterns' => [],
    ];

    $json = file_get_contents($path);
    $custom = json_decode($json, true);

    return [
        'functions' => array_merge(WP_FUNCTIONS, $custom['functions'] ?? []),
        'classes' => array_merge(WP_CLASSES, $custom['classes'] ?? []),
        'constants' => array_merge(WP_CONSTANTS, $custom['constants'] ?? []),
        'globals' => array_merge(WP_GLOBAL_VARS, $custom['globals'] ?? []),
        'exclude_patterns' => $custom['exclude_patterns'] ?? [],
    ];
}

function shouldExcludeFile(string $fileName, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (fnmatch($pattern, basename($fileName))) return true;
    }
    return false;
}

function colorize($text, $color = 'green') {
    $colors = ['red' => '0;31', 'green' => '0;32', 'yellow' => '1;33', 'blue' => '0;34'];
    return "\033[" . ($colors[$color] ?? '0') . "m$text\033[0m";
}

class WpFriendlyObfuscator extends NodeVisitorAbstract {
    private array $varMap = [], $funcMap = [], $classMap = [];
    private int $varCounter = 0, $funcCounter = 0, $classCounter = 0;
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    private function generateName(string $prefix, int $counter): string {
        return "__{$prefix}" . base_convert($counter, 10, 36);
    }

    public function enterNode(Node $node) {
        // 变量名混淆
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            if (in_array($node->name, $this->config['globals'], true)) return;
            if (!isset($this->varMap[$node->name])) {
                $this->varMap[$node->name] = $this->generateName('v', $this->varCounter++);
            }
            $node->name = $this->varMap[$node->name];
        }

        // 函数参数混淆
        if ($node instanceof Node\Param && $node->var instanceof Node\Expr\Variable) {
            $name = $node->var->name;
            if (is_string($name) && !in_array($name, $this->config['globals'], true)) {
                if (!isset($this->varMap[$name])) {
                    $this->varMap[$name] = $this->generateName('a', $this->varCounter++);
                }
                $node->var->name = $this->varMap[$name];
            }
        }

        // 函数定义混淆
        if ($node instanceof Node\Stmt\Function_) {
            $name = $node->name->name;
            if (!in_array($name, $this->config['functions'], true)) {
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = $this->generateName('f', $this->funcCounter++);
                }
                $node->name->name = $this->funcMap[$name];
            }
        }

        // 函数调用混淆
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            if (isset($this->funcMap[$name])) {
                $node->name = new Node\Name($this->funcMap[$name]);
            }
        }

        // 类名混淆
        if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
            $name = $node->name->name;
            if (!in_array($name, $this->config['classes'], true)) {
                if (!isset($this->classMap[$name])) {
                    $this->classMap[$name] = $this->generateName('c', $this->classCounter++);
                }
                $node->name->name = $this->classMap[$name];
            }
        }

        // 类实例化使用混淆类名
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $name = $node->class->toString();
            if (isset($this->classMap[$name])) {
                $node->class = new Node\Name($this->classMap[$name]);
            }
        }
    }
}

function obfuscateFile(string $filePath, array $config): ?string {
    $code = file_get_contents($filePath);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new WpFriendlyObfuscator($config));

    try {
        $ast = $parser->parse($code);
        $ast = $traverser->traverse($ast);
        $prettyPrinter = new PrettyPrinter\Standard();
        return $prettyPrinter->prettyPrintFile($ast);
    } catch (Error $e) {
        echo colorize("[Error] $filePath: " . $e->getMessage(), 'red') . "\n";
        return null;
    }
}

function obfuscateDirectory(string $inputDir, string $outputDir, array $config): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inputDir));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && !shouldExcludeFile($file->getFilename(), $config['exclude_patterns'])) {
            $inputPath = $file->getRealPath();
            $relativePath = substr($inputPath, strlen($inputDir));
            $outputPath = $outputDir . $relativePath;

            if (!is_dir(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0777, true);
            }

            $obfuscatedCode = obfuscateFile($inputPath, $config);
            if ($obfuscatedCode !== null) {
                file_put_contents($outputPath, $obfuscatedCode);
                echo colorize("✔ Obfuscated: $relativePath", 'green') . "\n";
            }
        }
    }
}

// CLI 启动
if ($argc < 3) {
    echo "Usage: php obfuscate.php <source_dir> <target_dir>\n";
    exit(1);
}

$source = rtrim($argv[1], '/\\');
$target = rtrim($argv[2], '/\\');
$config = loadConfig(__DIR__ . '/config.json');

obfuscateDirectory($source, $target, $config);
