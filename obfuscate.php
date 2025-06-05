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

class WpFriendlyObfuscator extends NodeVisitorAbstract
{
    private array $varMap = [];
    private array $funcMap = [];
    private array $classMap = [];
    private int $varCounter = 0;
    private int $funcCounter = 0;
    private int $classCounter = 0;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function enterNode(Node $node)
    {
        // 混淆变量
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            $name = $node->name;
            if (!in_array($name, $this->config['ignore_vars'] ?? [])) {
                if (!isset($this->varMap[$name])) {
                    $this->varMap[$name] = '__v' . $this->varCounter++;
                }
                $node->name = $this->varMap[$name];
            }
        }

        // 混淆函数声明
        if ($node instanceof Node\Stmt\Function_) {
            $name = $node->name->name;
            if (!in_array($name, $this->config['ignore_functions'] ?? [])) {
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = '__f' . $this->funcCounter++;
                }
                $node->name->name = $this->funcMap[$name];
            }
        }

        // 混淆函数调用
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            if (isset($this->funcMap[$name])) {
                $node->name = new Node\Name($this->funcMap[$name]);
            }
        }

        // 混淆类名
        if ($node instanceof Node\Stmt\Class_ && $node->name) {
            $name = $node->name->name;
            if (!in_array($name, $this->config['ignore_classes'] ?? [])) {
                if (!isset($this->classMap[$name])) {
                    $this->classMap[$name] = '__c' . $this->classCounter++;
                }
                $node->name->name = $this->classMap[$name];
            }
        }

        // 混淆类使用
        if (
            $node instanceof Node\Expr\New_
            || $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\ClassConstFetch
        ) {
            if ($node->class instanceof Node\Name) {
                $name = $node->class->toString();
                if (isset($this->classMap[$name])) {
                    $node->class = new Node\Name($this->classMap[$name]);
                }
            }
        }

        // 混淆方法调用
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $name = $node->name->name;
            if (!isset($this->funcMap[$name])) {
                $this->funcMap[$name] = '__f' . $this->funcCounter++;
            }
            $node->name->name = $this->funcMap[$name];
        }

        // 混淆类方法定义
        if ($node instanceof Node\Stmt\ClassMethod) {
            $name = $node->name->name;
            if (!in_array($name, $this->config['ignore_functions'] ?? [])) {
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = '__f' . $this->funcCounter++;
                }
                $node->name->name = $this->funcMap[$name];
            }
        }

        return null;
    }

    public function getMappings(): array
    {
        return [
            'variables' => $this->varMap,
            'functions' => $this->funcMap,
            'classes' => $this->classMap,
        ];
    }
}

function colorize(string $text, string $color): string
{
    $colors = [
        'red' => '0;31',
        'green' => '0;32',
        'blue' => '0;34',
        'yellow' => '1;33',
        'cyan' => '0;36',
    ];
    $code = $colors[$color] ?? '0';
    return "\033[" . $code . "m$text\033[0m";
}

function obfuscateDirectory(string $inputDir, string $outputDir, array $config = []): void
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $prettyPrinter = new PrettyPrinter\Standard();
    $visitor = new WpFriendlyObfuscator($config);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inputDir));

    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = str_replace($inputDir, '', $file->getPathname());
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $relativePath;
        $outputFileDir = dirname($outputPath);

        if (!is_dir($outputFileDir)) {
            mkdir($outputFileDir, 0777, true);
        }

        try {
            $code = file_get_contents($file->getPathname());
            $ast = $parser->parse($code);
            $ast = $traverser->traverse($ast);
            $obfuscatedCode = $prettyPrinter->prettyPrintFile($ast);
            file_put_contents($outputPath, $obfuscatedCode);
            echo colorize("✓ Obfuscated: $relativePath", 'green') . "\n";
        } catch (Throwable $e) {
            echo colorize("✗ Failed to parse: $relativePath\n  " . $e->getMessage(), 'red') . "\n";
        }
    }

    // 保存映射文件
    file_put_contents($outputDir . '/mappings.json', json_encode($visitor->getMappings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo colorize("✓ Saved mappings to mappings.json", 'blue') . "\n";
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

