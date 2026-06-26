<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/packages/platform/src',
        __DIR__ . '/packages/api-platform/src',
        __DIR__ . '/packages/admin-bundle/src',
        __DIR__ . '/packages/tenant-bundle/src',
        __DIR__ . '/packages/sequence-bundle/src',
        __DIR__ . '/packages/workflow-bundle/src',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
        'phpdoc_order' => true,
        'phpdoc_align' => false,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache');
