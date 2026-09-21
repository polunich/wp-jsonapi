<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    // a fixture that writes global classes with a leading backslash on purpose,
    // which `global_namespace_import` would import;
    // a path given on the command line replaces this finder, and the exclusion with it
    ->notPath('Fixtures/Architecture/CodeStyle/QualifiedNames.php')
    ->append([__DIR__ . '/bin/wp-jsonapi']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        // `@PER-CS` limits this rule to `use` statements;
        // its default `extra` collapses blank lines to one, as PER-CS 3 requires in the file header
        'no_extra_blank_lines' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => null, 'import_functions' => null],
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'case_sensitive' => false, 'imports_order' => null],
    ])
    ->setFinder($finder);
