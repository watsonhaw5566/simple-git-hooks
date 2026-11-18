<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])
    ->exclude('vendor')
    ->name('*.php')
    ->name('simple-git-hooks');

// 以 @PSR12 为基础；对与项目现状风格冲突的规则显式关闭。
return (new Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => [
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'declare_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'no_unused_imports' => true,
        'ordered_class_elements' => ['order' => ['use_trait', 'case', 'constant', 'property', 'construct']],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_add_missing_param_annotation' => false,
        'phpdoc_line_span' => false,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments']],
    ])
    ->setFinder($finder);
