<?php
/**
 *
 *  此混淆脚本可以通用的原因：
 *
 * 脚本本身无插件耦合：
 * 你的混淆器 WpFriendlyObfuscator 并没有绑定任何特定插件名或结构，使用的是基于 AST（抽象语法树）的方式处理变量、函数、类和方法，非常灵活。
 * 使用 config.json 灵活配置：
 * 白名单项通过配置文件管理（如 functions、classes、globals 等），只需根据每个插件的实际情况调整即可，无需改动主脚本。
 * 混淆结果映射保存：
 * 每次混淆都会保存 obfuscation-map.json，便于调试或反查混淆关系，对多个项目开发来说是良好实践。
 * 排除特定文件的机制存在：
 * 使用 exclude_patterns 支持按文件名/模式排除不应混淆的文件，如 index.php, loader.php，适合插件中某些必须公开的入口文件。
 *
 *
 * */
error_reporting(E_ALL);
ini_set('display_errors','on');

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

// 默认白名单配置(都是wordpress特有的)
const WP_FUNCTIONS = [];
const WP_CLASSES = [];
const WP_CONSTANTS = [];
const WP_GLOBAL_VARS = [];

// 加载自定义配置（如白名单）
function loadConfig(string $path): array {

    if (!file_exists($path)) {
        throw new \Exception("Config file not found: $path");
    }

    $json = file_get_contents($path);
    $custom = json_decode($json, true);

    if (!is_array($custom) || !isset($custom['whitelist'])) {
        throw new \Exception("Invalid config structure in $path");
    }

    $whitelist = $custom['whitelist'];

    return [
        'functions' => array_merge(WP_FUNCTIONS, $whitelist['functions'] ?? []),
        'classes' => array_merge(WP_CLASSES, $whitelist['classes'] ?? []),
        'methods' => $whitelist['methods'] ?? [],
        'globals' => array_merge(WP_GLOBAL_VARS, $whitelist['globals'] ?? []),
        'variables' => $whitelist['variables'] ?? [],
        'constants' => array_merge(WP_CONSTANTS, $whitelist['constants'] ?? []),
        'exclude_patterns' => $whitelist['exclude_patterns'] ?? [],
    ];
}

//匹配到的文件不需要混淆
function shouldExcludeFile(string $fileName, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (fnmatch($pattern, basename($fileName))) return true;
    }
    return false;
}


// 彩色输出函数
function colorize(string $text, string $color): string {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

//主混淆类 #########################################################################################
class WpFriendlyObfuscator extends NodeVisitorAbstract {
    private array $varMap = [], $funcMap = [], $classMap = [], $methodMap = [];
    private int $varCounter = 0, $funcCounter = 0, $classCounter = 0, $methodCounter = 0;
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    private function generateName(string $prefix, int $counter): string {
        return "__{$prefix}" . base_convert($counter, 10, 36);
    }

    public function enterNode(Node $node) {
        // 变量名混淆 -----------------------------------------------------------------
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            if ($node->hasAttribute('already_obfuscated')) return;// 已经处理过，跳过
            if (in_array($node->name, $this->config['globals'], true)) return;

//            if (!isset($this->varMap[$node->name])) {
//                $this->varMap[$node->name] = $this->generateName('v', $this->varCounter++);
//            }
            if (!isset($this->varMap[$node->name])) {
                $this->varMap[$node->name] = $this->generateName('v', $this->varCounter++);
            } else {
                // 调试检查：如果变量已经混淆过，但又被处理，则记录警告
                if ($this->varMap[$node->name] !== $node->name) {
                    file_put_contents("debug.log","Variable \${$node->name} was already obfuscated to {$this->varMap[$node->name]}".PHP_EOL,FILE_APPEND);
                }
            }

            $node->name = $this->varMap[$node->name];
            $node->setAttribute('already_obfuscated', true);//标记已处理
        }

        // 函数参数混淆 -----------------------------------------------------------------
        if ($node instanceof Node\Param && $node->var instanceof Node\Expr\Variable) {
            $name = $node->var->name;
            if (is_string($name) && !in_array($name, $this->config['variables'], true)) return;

            if (!isset($this->varMap[$name])) {
                $this->varMap[$name] = $this->generateName('a', $this->varCounter++);
            }

            $node->var->name = $this->varMap[$name];
        }

        // 函数定义混淆 -----------------------------------------------------------------
        if ($node instanceof Node\Stmt\Function_) {
            $name = $node->name->name;
            if (!in_array($name, $this->config['functions'], true)) {
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = $this->generateName('f', $this->funcCounter++);
                }
                $node->name->name = $this->funcMap[$name];
            }
        }

        // 函数调用混淆 -----------------------------------------------------------------
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            if (isset($this->funcMap[$name])) {
                $node->name = new Node\Name($this->funcMap[$name]);
            }
        }

        //专门处理 function_exists('xxx') 这类特殊字符串引用场景。
        if (
            $node instanceof Node\Expr\FuncCall &&
            $node->name instanceof Node\Name &&
            strtolower((string)$node->name) === 'function_exists' &&
            isset($node->args[0])
        ) {
            $arg = $node->args[0]->value;

            // 处理拼接字符串
            if ($arg instanceof Node\Expr\BinaryOp\Concat && $arg->right instanceof Node\Scalar\String_) {
                $funcName = ltrim($arg->right->value, '\\');
                if (isset($this->funcMap[$funcName])) {
                    $arg->right->value = '\\' . $this->funcMap[$funcName];
                }
            }

            // 处理普通字符串
            if ($arg instanceof Node\Scalar\String_) {
                $funcName = ltrim($arg->value, '\\');
                if (isset($this->funcMap[$funcName])) {
                    $arg->value = $this->funcMap[$funcName];
                }
            }
        }

        // 类名混淆 -----------------------------------------------------------------
        if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
            $name = $node->name->name;
            // ✅ 添加日志输出
            //echo "检查类名: $name\n";
            //echo "白名单类列表: " . implode(', ', $this->config['classes']) . "\n";

            if (!in_array($name, $this->config['classes'], true)) {
                if (!isset($this->classMap[$name])) {
                    $this->classMap[$name] = $this->generateName('c', $this->classCounter++);
                }
                $node->name->name = $this->classMap[$name];
            }
        }

        // 类实例化混淆 -----------------------------------------------------------------
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $name = $node->class->toString();
            if (isset($this->classMap[$name])) {
                $node->class = new Node\Name($this->classMap[$name]);
            }
        }

        // 类方法定义混淆 -----------------------------------------------------------------
        if ($node instanceof Node\Stmt\ClassMethod) {
            $methodName = $node->name->name;
            if (!in_array($methodName, $this->config['methods'] ?? [], true)) {
                if (!isset($this->methodMap[$methodName])) {
                    $this->methodMap[$methodName] = $this->generateName('m', $this->methodCounter++);
                }
                $node->name->name = $this->methodMap[$methodName];
            }
        }


        // 类方法调用混淆 - 静态方式
        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;

            // 跳过白名单中的方法名（如 __construct, get_instance 等）
            if (in_array($methodName, $this->config['methods'] ?? [], true)) {
                return;
            }

            // 生成并映射混淆名
            if (!isset($this->methodMap[$methodName])) {
                $this->methodMap[$methodName] = $this->generateName('m', $this->methodCounter++);
            }

            $node->name->name = $this->methodMap[$methodName];
        }

        // 类方法调用混淆 - 非静态方式（对象调用）
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;

            // 跳过白名单中的方法名（如 __construct, get_instance 等）
            if (in_array($methodName, $this->config['methods'] ?? [], true)) {
                return;
            }

            // 映射混淆名
            if (!isset($this->methodMap[$methodName])) {
                $this->methodMap[$methodName] = $this->generateName('m', $this->methodCounter++);
            }

            $node->name->name = $this->methodMap[$methodName];
        }

    }

    public function saveMapToFile(string $filePath): void {
        $map = [
            'variables' => $this->varMap,
            'functions' => $this->funcMap,
            'classes'   => $this->classMap,
            'methods'   => $this->methodMap,
        ];

        file_put_contents($filePath, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function obfuscateFile(string $filePath, WpFriendlyObfuscator $obfuscator): ?string {
    $code = file_get_contents($filePath);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($obfuscator);

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

// 主混淆函数（第一阶段）
function obfuscateDirectory(string $inputDir, string $outputDir, array $config): void {
    $inputDir = rtrim(realpath($inputDir), DIRECTORY_SEPARATOR);
    $outputDir = rtrim($outputDir, DIRECTORY_SEPARATOR);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inputDir));

    // ✅ 创建统一的 Obfuscator 实例
    $obfuscator = new WpFriendlyObfuscator($config);

    foreach ($files as $file) {
        if (
            $file->isFile() &&
            $file->getExtension() === 'php' &&
            !shouldExcludeFile($file->getFilename(), $config['exclude_patterns'])
        ) {

            $inputPath = $file->getRealPath();

            // 计算相对路径
            $relativePath = substr($inputPath, strlen($inputDir) + 1);

            // 生成输出路径
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $relativePath;

            // 确保目录存在
            if (!is_dir(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0777, true);
            }

            // 使用统一的 obfuscator 实例
            $obfuscatedCode = obfuscateFile($inputPath, $obfuscator);
            if ($obfuscatedCode !== null) {
                file_put_contents($outputPath, $obfuscatedCode);
                //echo colorize("✔ Obfuscated: $relativePath", 'green') . "\n";
                echo colorize("[Phase1 Done] Functions and methods obfuscated.\n", 'green');
            }
        }
    }

    // ✅ 所有处理完后统一保存映射文件
    $obfuscator->saveMapToFile($outputDir . DIRECTORY_SEPARATOR . 'obfuscation-map.json');
}

// 第二阶段：更新字符串中的回调名
//替换钩子回谳函数字符串类 #########################################################################################
class CallbackNameUpdater extends NodeVisitorAbstract {
    private $funcMap, $methodMap;
    public function __construct(array $funcMap, array $methodMap) {
        $this->funcMap = $funcMap;
        $this->methodMap = $methodMap;
    }
    public function enterNode(Node $node) {
        // add_action / add_filter(string callback)
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $fname = $node->name->toString();
            if (in_array($fname, ['add_action','add_filter'], true) && isset($node->args[1])) {
                $arg = $node->args[1]->value;
                if ($arg instanceof Node\Scalar\String_) {
                    $orig = $arg->value;
                    if (isset($this->funcMap[$orig])) {
                        $arg->value = $this->funcMap[$orig];
                    }
                }
                if ($arg instanceof Node\Expr\Array_ && count($arg->items) >= 2) {
                    $item = $arg->items[1];
                    if ($item->value instanceof Node\Scalar\String_) {
                        $orig = $item->value->value;
                        if (isset($this->methodMap[$orig])) {
                            $item->value->value = $this->methodMap[$orig];
                        }
                    }
                }
            }
        }
        // register_rest_route callback
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
            && $node->name->toString() === 'register_rest_route' && isset($node->args[2])) {
            $arg = $node->args[2]->value;
            if ($arg instanceof Node\Expr\Array_) {
                foreach ($arg->items as $item) {
                    if ($item->key instanceof Node\Scalar\String_
                        && $item->key->value === 'callback'
                        && $item->value instanceof Node\Scalar\String_) {
                        $orig = $item->value->value;
                        if (isset($this->funcMap[$orig])) {
                            $item->value->value = $this->funcMap[$orig];
                        }
                    }
                }
            }
        }

        if ($node instanceof Node\Expr\Array_ && count($node->items) === 2) {
            $first = $node->items[0]->value;
            $second = $node->items[1]->value;

            // 形如 [$this, 'method_name']
            if ($first instanceof Node\Expr\Variable && $first->name === 'this' && $second instanceof Node\Scalar\String_) {
                $orig = $second->value;
                if (isset($this->methodMap[$orig])) {
                    $second->value = $this->methodMap[$orig];
                    // 如果想调试，可以打开下面这行
                    // echo "Callback method string updated: $orig -> {$this->methodMap[$orig]}\n";
                }
            }
        }


    }
}

// 遍历目标目录并替换回调名
function obfuscateDirectoryPhase2(string $dir, NodeTraverser $traverser): void {
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $printer = new PrettyPrinter\Standard();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $code = file_get_contents($file->getRealPath());
            try {
                $ast = $parser->parse($code);
                $ast = $traverser->traverse($ast);
                $new = $printer->prettyPrintFile($ast);
                file_put_contents($file->getRealPath(), $new);
                echo colorize("[Phase2 OK] ".$file->getFilename()."\n", 'green');
            } catch (Error $e) {
                echo colorize("[Phase2 Error] {$file->getFilename()}: ".$e->getMessage()."\n", 'red');
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
try {
    $config = loadConfig(__DIR__ . '/obfuscate-config.json');
} catch (Exception $e) {
}

// 执行第一阶段：混淆所有PHP文件中的函数、变量、方法、类名等。
obfuscateDirectory($source, $target, $config);

// 第二阶段：根据映射修改回调名
$map = json_decode(file_get_contents($target.'/obfuscation-map.json'), true);
$traverser = new NodeTraverser();
$traverser->addVisitor(new CallbackNameUpdater($map['functions'], $map['methods']));
obfuscateDirectoryPhase2($target, $traverser);

echo colorize("\n[Done] All stages completed.\n", 'yellow');

