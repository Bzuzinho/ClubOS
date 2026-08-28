import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';

export default [
    {
        ignores: [
            'node_modules/**',
            'public/build/**',
            'vendor/**',
            'resources/js/**/*.d.ts',
        ],
    },
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: {
            parser: tseslint.parser,
            parserOptions: {
                ecmaVersion: 'latest',
                sourceType: 'module',
                ecmaFeatures: {
                    jsx: true,
                },
            },
        },
        plugins: {
            'react-hooks': reactHooks,
        },
        rules: {
            'no-debugger': 'error',
            'no-duplicate-imports': 'error',
            'no-unreachable': 'error',
            'react-hooks/rules-of-hooks': 'error',
        },
    },
];
